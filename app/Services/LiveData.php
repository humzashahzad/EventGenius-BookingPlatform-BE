<?php

namespace App\Services;

use App\Events\EntityChanged;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Store;
use App\Models\StoreLandingPage;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Venue;
use App\Models\VenueAmenity;
use App\Models\VenueImage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects "record changed" signals during a request / job / command and broadcasts each
 * distinct change once at the end (after the response has been sent, after commit), as
 * App\Events\EntityChanged on the queue. See docs/live-data.md.
 *
 * Never throws: a failure here must not break the write that triggered it.
 */
class LiveData
{
    public const MAX_FIELDS = 10;

    /** Flush early when this many distinct changes are buffered (long-running commands). */
    public const FLUSH_AT = 100;

    /** Attributes that are never reported, and that alone never trigger an event. */
    public const IGNORED_FIELDS = [
        'created_at', 'updated_at', 'password', 'remember_token', 'last_seen_at', 'last_used_at',
    ];

    /** Related ids that go into the payload. */
    public const PAYLOAD_KEYS = ['venue_id', 'store_id', 'booking_id', 'client_id'];

    /** Related ids used only to pick recipients (never sent). */
    public const ROUTING_KEYS = ['owner_id', 'user_id'];

    public const ENTITY_BY_CLASS = [
        Booking::class          => 'booking',
        Payment::class          => 'payment',
        Venue::class            => 'venue',
        VenueImage::class       => 'venue_image',
        VenueAmenity::class     => 'venue_amenity',
        Store::class            => 'store',
        StoreLandingPage::class => 'landing_page',
        Review::class           => 'review',
        User::class             => 'user',
        Category::class         => 'category',
        UserSession::class      => 'session',
    ];

    /** @var array<string, array> pending changes keyed by entity:id:action */
    private array $buffer = [];

    /** @var array<string, mixed> per-flush lookup memo */
    private array $memo = [];

    /**
     * @param Closure|null $dispatcher fn(EntityChanged $event): void — defaults to event()
     * @param Closure|null $lookup     fn(string $kind, int $id): mixed — defaults to small DB queries
     */
    public function __construct(
        private ?Closure $dispatcher = null,
        private ?Closure $lookup = null,
    ) {}

    // ── Entry points ─────────────────────────────────────────────────────────

    /**
     * Record that a record changed. Safe to call anywhere; inside a transaction the change is only
     * kept if the transaction commits.
     *
     * @param array $related venue_id / store_id / booking_id / client_id, plus owner_id / user_id for routing
     * @param array $fields  changed attribute names (updates)
     */
    public static function changed(Model|string $entity, ?int $id = null, string $action = 'updated', array $related = [], array $fields = []): void
    {
        try {
            if (!static::enabled()) {
                return;
            }
            $self = app(self::class);
            $record = fn () => $self->record($entity, $id, $action, $related, $fields);
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($record);
            } else {
                $record();
            }
        } catch (Throwable $e) {
            self::warn('Live data change could not be recorded: ' . $e->getMessage());
        }
    }

    /** Flush whatever is pending (called on terminate and after each queued job). */
    public static function flushPending(): void
    {
        try {
            if (app()->resolved(self::class)) {
                app(self::class)->flush();
            }
        } catch (Throwable $e) {
            self::warn('Live data flush failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether change events should be produced at all. Off when broadcasting is disabled. Also off
     * in the one setup that would deadlock: PHP's single-threaded dev server publishing
     * synchronously (sync queue) to its own host:port. Production (php-fpm + queue) always broadcasts.
     */
    public static function enabled(): bool
    {
        if (!config('broadcasting.live_data.enabled', true)) {
            return false;
        }
        $connection = (string) config('broadcasting.default', 'null');
        if ($connection === '' || in_array($connection, ['null', 'log'], true)) {
            return false;
        }
        if (PHP_SAPI !== 'cli-server' || config('queue.default') !== 'sync' || !in_array($connection, ['reverb', 'pusher'], true)) {
            return true;
        }

        $host = strtolower((string) config("broadcasting.connections.{$connection}.options.host", ''));
        $port = (int) config("broadcasting.connections.{$connection}.options.port", 0);
        if ($host === '' || $port <= 0) {
            return true;
        }
        $norm = static fn (string $h) => in_array($h, ['127.0.0.1', '::1'], true) ? 'localhost' : $h;
        $request = request();
        $reqPort = (int) ($request->getPort() ?: ($request->isSecure() ? 443 : 80));

        return !($norm($host) === $norm(strtolower($request->getHost())) && $port === $reqPort);
    }

    // ── Buffer ───────────────────────────────────────────────────────────────

    public function record(Model|string $entity, ?int $id = null, string $action = 'updated', array $related = [], array $fields = []): void
    {
        if ($entity instanceof Model) {
            $id ??= (int) $entity->getKey();
            $entity = self::ENTITY_BY_CLASS[$entity::class] ?? self::ENTITY_BY_CLASS[get_parent_class($entity) ?: ''] ?? strtolower(class_basename($entity));
        }
        $id = (int) $id;
        if ($entity === '' || $id <= 0 || !in_array($action, ['created', 'updated', 'deleted'], true)) {
            return;
        }

        if ($action === 'updated') {
            $kept = array_values(array_diff(array_map('strval', $fields), self::IGNORED_FIELDS));
            if ($fields !== [] && $kept === []) {
                return; // only bookkeeping columns changed (e.g. a session "last used" touch)
            }
            $fields = $kept;
        } else {
            $fields = [];
        }

        $related = array_filter(
            array_map(fn ($v) => (int) $v, array_intersect_key($related, array_flip([...self::PAYLOAD_KEYS, ...self::ROUTING_KEYS]))),
            fn (int $v) => $v > 0,
        );

        // A record created in this request is already "new" to everyone: later updates add nothing.
        if ($action === 'updated' && isset($this->buffer["{$entity}:{$id}:created"])) {
            return;
        }

        $key = "{$entity}:{$id}:{$action}";
        if (isset($this->buffer[$key])) {
            $entry = &$this->buffer[$key];
            $entry['related'] = $related + $entry['related'];
            $entry['fields'] = array_slice(array_values(array_unique([...$entry['fields'], ...$fields])), 0, self::MAX_FIELDS);
            unset($entry);
        } else {
            $this->buffer[$key] = [
                'entity'  => $entity,
                'id'      => $id,
                'action'  => $action,
                'related' => $related,
                'fields'  => array_slice($fields, 0, self::MAX_FIELDS),
            ];
        }

        if (count($this->buffer) >= self::FLUSH_AT) {
            $this->flush();
        }
    }

    public function pending(): array
    {
        return array_values($this->buffer);
    }

    /** Broadcast and clear the buffer. Returns the number of events dispatched. */
    public function flush(): int
    {
        $entries = $this->buffer;
        $this->buffer = [];
        $sent = 0;

        foreach ($entries as $entry) {
            try {
                foreach ($this->eventsFor($entry) as $event) {
                    $this->dispatch($event);
                    $sent++;
                }
            } catch (Throwable $e) {
                self::warn("Live data broadcast failed for {$entry['entity']} #{$entry['id']}: " . $e->getMessage());
            }
        }

        $this->memo = [];
        return $sent;
    }

    // ── Building events ──────────────────────────────────────────────────────

    /** @return EntityChanged[] */
    public function eventsFor(array $entry): array
    {
        [$related, $userIds, $venueIds] = $this->resolve($entry);

        $payload = ['entity' => $entry['entity'], 'id' => $entry['id'], 'action' => $entry['action']];
        foreach (self::PAYLOAD_KEYS as $key) {
            if (!empty($related[$key])) {
                $payload[$key] = (int) $related[$key];
            }
        }
        if ($entry['action'] === 'updated' && $entry['fields'] !== []) {
            $payload['fields'] = $entry['fields'];
        }
        $payload['at'] = gmdate('Y-m-d\TH:i:s\Z');

        return EntityChanged::fanOut($payload, $userIds, $venueIds);
    }

    /**
     * Fill in related ids and recipients. At most one small query per entity (memoised per flush),
     * and none when the observer already supplied everything.
     *
     * @return array{0: array, 1: int[], 2: int[]} [related ids, user ids, venue ids]
     */
    private function resolve(array $entry): array
    {
        $r = $entry['related'];
        $id = $entry['id'];
        $users = array_values(array_filter([$r['user_id'] ?? null, $r['owner_id'] ?? null]));
        $venueIds = [];

        switch ($entry['entity']) {
            case 'booking':
                $r['booking_id'] = $id;
                $r = $this->withOwner($r);
                $users[] = $r['client_id'] ?? null;
                break;

            case 'payment':
                if (!empty($r['booking_id']) && (empty($r['venue_id']) || empty($r['store_id']) || empty($r['owner_id']))) {
                    $b = (array) ($this->find('booking', (int) $r['booking_id']) ?? []);
                    $r += array_filter([
                        'venue_id'  => $b['venue_id'] ?? null,
                        'store_id'  => $b['store_id'] ?? null,
                        'client_id' => $b['client_id'] ?? null,
                        'owner_id'  => $b['user_id'] ?? null,
                    ]);
                }
                $users[] = $r['client_id'] ?? null;
                $users[] = $r['owner_id'] ?? null;
                break;

            case 'review':
                $r = $this->withOwner($r);
                $users[] = $r['client_id'] ?? null;
                break;

            case 'venue':
                $r['venue_id'] = $id;
                $r = $this->withOwner($r);
                break;

            case 'venue_image':
            case 'venue_amenity':
            case 'landing_page':
                $r = $this->withOwner($r);
                break;

            case 'store':
                $r['store_id'] = $id;
                $r['owner_id'] ??= $this->find('store', $id);
                $statusChanged = $entry['action'] !== 'updated' || array_intersect($entry['fields'], ['status', 'deleted_at']) !== [];
                if ($statusChanged) {
                    $venueIds = array_map('intval', (array) ($this->find('store_venues', $id) ?? []));
                }
                break;

            case 'user':
                $users[] = $id;
                break;
        }

        $users[] = $r['owner_id'] ?? null;
        $users = array_values(array_unique(array_filter(array_map('intval', $users))));

        return [$r, $users, $venueIds];
    }

    /** Add store_id / owner_id from venue_id or store_id when missing. */
    private function withOwner(array $r): array
    {
        if (!empty($r['owner_id']) && !empty($r['store_id'])) {
            return $r;
        }
        if (!empty($r['venue_id']) && empty($r['store_id'])) {
            $v = (array) ($this->find('venue', (int) $r['venue_id']) ?? []);
            $r['store_id'] = (int) ($v['store_id'] ?? 0) ?: null;
            if (empty($r['owner_id']) && !empty($v['user_id'])) {
                $r['owner_id'] = (int) $v['user_id'];
            }
        }
        if (empty($r['owner_id']) && !empty($r['store_id'])) {
            $r['owner_id'] = $this->find('store', (int) $r['store_id']);
        }
        return array_filter($r);
    }

    private function find(string $kind, int $id): mixed
    {
        $key = "{$kind}:{$id}";
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->lookup ? ($this->lookup)($kind, $id) : $this->query($kind, $id);
        }
        return $this->memo[$key];
    }

    private function query(string $kind, int $id): mixed
    {
        return match ($kind) {
            'store' => ($uid = DB::table('stores')->where('id', $id)->value('user_id')) ? (int) $uid : null,
            'venue' => (array) (DB::table('venues')->leftJoin('stores', 'stores.id', '=', 'venues.store_id')
                ->where('venues.id', $id)->first(['venues.store_id', 'stores.user_id']) ?? []),
            'booking' => (array) (DB::table('bookings')->leftJoin('stores', 'stores.id', '=', 'bookings.store_id')
                ->where('bookings.id', $id)->first(['bookings.venue_id', 'bookings.store_id', 'bookings.client_id', 'stores.user_id']) ?? []),
            'store_venues' => DB::table('venues')->where('store_id', $id)->whereNull('deleted_at')
                ->orderBy('id')->limit(200)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            default => null,
        };
    }

    private static function warn(string $message): void
    {
        try {
            Log::warning($message);
        } catch (Throwable) {
            // logging itself unavailable: stay silent rather than break the caller
        }
    }

    private function dispatch(EntityChanged $event): void
    {
        if ($this->dispatcher) {
            ($this->dispatcher)($event);
            return;
        }
        if (!app()->runningInConsole()) {
            $event->dontBroadcastToCurrentUser(); // honours X-Socket-ID
        }
        event($event);
    }
}
