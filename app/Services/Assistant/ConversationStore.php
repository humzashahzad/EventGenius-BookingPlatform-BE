<?php

namespace App\Services\Assistant;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Conversation memory in the cache (no database rows): search state, the venues last shown,
 * which venue we are talking about and a turn counter. Nothing the visitor typed is stored.
 */
class ConversationStore
{
    public const TTL_SECONDS = 7200;
    public const MAX_TURNS = 30;
    private const PREFIX = 'assistant:conv:';

    public function __construct(private Repository $cache) {}

    public static function blank(): array
    {
        return [
            'state' => self::blankState(),
            'shown' => [],        // compact venues of the last result page, in display order
            'focus' => null,      // venue id the conversation is about
            'pending' => null,    // what we just asked for: event_type | city | guests | budget | venue | date
            'pending_q' => null,  // the question waiting for "which venue?"
            'offer' => null,      // what a plain "yes" means next turn
            'last' => null,       // kind of the last answer (price, reviews, search …) for short follow-ups
            'asked' => [],        // slots asked (or skipped) once already
            'page' => 1,
            'search_id' => null,
            'total' => null,
            'turns' => 0,
        ];
    }

    public static function blankState(): array
    {
        return [
            'city' => null, 'event_type' => null, 'guests' => null, 'max_price' => null, 'min_price' => null,
            'date' => null, 'amenities' => [], 'terms' => [], 'intents' => [], 'sort' => 'best_match',
            'min_capacity' => null, 'max_capacity' => null, 'min_rating' => null,
        ];
    }

    /** @return array{0: string, 1: array} [id, conversation] — a fresh one when the id is unknown or expired. */
    public function load(?string $id): array
    {
        if (is_string($id) && Str::isUuid($id)) {
            $conv = $this->cache->get(self::PREFIX . strtolower($id));
            if (is_array($conv)) {
                return [strtolower($id), array_replace(self::blank(), $conv)];
            }
        }
        return [(string) Str::uuid(), self::blank()];
    }

    public function save(string $id, array $conv): void
    {
        $this->cache->put(self::PREFIX . $id, $conv, self::TTL_SECONDS);
    }

    public function forget(string $id): void
    {
        $this->cache->forget(self::PREFIX . $id);
    }
}
