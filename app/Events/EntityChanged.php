<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "A record changed" signal for the people viewing it (see docs/live-data.md).
 *
 * The payload carries ids only. One change becomes at most two events: a private one
 * (the involved users' `user.{id}` channels + `admins`) and a public one (`venue.{id}` /
 * `catalog`) whose payload never contains the customer id. Created through
 * App\Services\LiveData, which coalesces changes per request and dispatches after commit.
 */
class EntityChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const AUDIENCE_PRIVATE = 'private';
    public const AUDIENCE_PUBLIC = 'public';

    /** Venue fields that change how a venue appears in listings/search. */
    public const CATALOG_VENUE_FIELDS = [
        'name', 'slug', 'thumbnail', 'city', 'state', 'address', 'status', 'is_featured', 'avg_rating',
        'total_reviews', 'price_per_hour', 'price_per_day', 'price_per_event', 'price_per_head', 'pricing_type',
        'capacity_min', 'capacity_max', 'event_types', 'search_boost', 'deleted_at',
    ];

    /** Only dispatch once the surrounding transaction (if any) has committed. */
    public bool $afterCommit = true;

    /** Broadcast job settings (copied onto the queued BroadcastEvent job). */
    public int $tries = 3;
    public int $timeout = 15;

    /**
     * @param array  $payload  entity, id, action, optional venue_id/store_id/booking_id/client_id, fields, at
     * @param string $audience self::AUDIENCE_PRIVATE or self::AUDIENCE_PUBLIC
     * @param int[]  $userIds  users whose `user.{id}` channel receives the private event
     * @param int[]  $venueIds extra venue pages to notify (e.g. every venue of a store whose status changed)
     */
    public function __construct(
        public array $payload,
        public string $audience = self::AUDIENCE_PRIVATE,
        public array $userIds = [],
        public array $venueIds = [],
    ) {}

    /**
     * Build the events for one change: always the private one, plus a public one when the change
     * affects a public page.
     *
     * @return self[]
     */
    public static function fanOut(array $payload, array $userIds = [], array $venueIds = []): array
    {
        $events = [new self($payload, self::AUDIENCE_PRIVATE, $userIds)];
        if (self::publicChannelNames($payload, $venueIds) !== []) {
            $events[] = new self($payload, self::AUDIENCE_PUBLIC, [], $venueIds);
        }
        return $events;
    }

    public function broadcastOn(): array
    {
        if ($this->audience === self::AUDIENCE_PUBLIC) {
            return array_map(fn (string $name) => new Channel($name), self::publicChannelNames($this->payload, $this->venueIds));
        }

        return array_map(fn (string $name) => new PrivateChannel($name), self::privateChannelNames($this->userIds));
    }

    public function broadcastAs(): string
    {
        return 'entity.changed';
    }

    public function broadcastWith(): array
    {
        $payload = $this->payload;
        if ($this->audience === self::AUDIENCE_PUBLIC) {
            unset($payload['client_id']);
        }
        return $payload;
    }

    public function broadcastQueue(): ?string
    {
        return config('broadcasting.live_data.queue') ?: null;
    }

    // ── Channel selection (pure, unit tested) ────────────────────────────────

    /** @return string[] channel names without the "private-" prefix */
    public static function privateChannelNames(array $userIds): array
    {
        $names = [];
        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            if ($id > 0) {
                $names[] = "user.{$id}";
            }
        }
        $names[] = 'admins';
        return $names;
    }

    /** @return string[] */
    public static function publicChannelNames(array $payload, array $venueIds = []): array
    {
        $entity = (string) ($payload['entity'] ?? '');
        $action = (string) ($payload['action'] ?? 'updated');
        $fields = (array) ($payload['fields'] ?? []);
        $venueId = (int) ($payload['venue_id'] ?? 0);
        $names = [];

        switch ($entity) {
            case 'booking':      // availability
            case 'review':       // ratings + review list
            case 'venue_image':
            case 'venue_amenity':
                if ($venueId > 0) {
                    $names[] = "venue.{$venueId}";
                }
                break;

            case 'venue':
                $id = (int) ($payload['id'] ?? 0);
                if ($id > 0) {
                    $names[] = "venue.{$id}";
                }
                if ($action !== 'updated' || array_intersect($fields, self::CATALOG_VENUE_FIELDS) !== []) {
                    $names[] = 'catalog';
                }
                break;

            case 'store':
                foreach (array_unique(array_map('intval', $venueIds)) as $vid) {
                    if ($vid > 0) {
                        $names[] = "venue.{$vid}";
                    }
                }
                $names[] = 'catalog';
                break;

            case 'landing_page':
            case 'category':
                $names[] = 'catalog';
                break;

            // payment, user, session: private only
        }

        return $names;
    }
}
