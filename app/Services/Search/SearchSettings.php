<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin-tunable ranking settings, stored as one JSON row ('search.ranking') in app_settings.
 */
class SearchSettings
{
    public const KEY = 'search.ranking';
    private const CACHE_KEY = 'search:ranking:settings';
    private const CACHE_TTL = 600; // seconds

    public const SIGNALS = [
        'relevance', 'quality', 'popularity', 'reliability', 'price_fit',
        'capacity_fit', 'distance', 'engagement', 'freshness', 'personal', 'sentiment',
    ];

    public static function defaults(): array
    {
        return [
            'weights' => [
                'relevance' => 8, 'quality' => 7, 'popularity' => 5, 'reliability' => 3, 'price_fit' => 5,
                'capacity_fit' => 5, 'distance' => 5, 'engagement' => 3, 'freshness' => 2, 'personal' => 4,
                'sentiment' => 5,
            ],
            'featured_boost' => 5,
            'personalization' => true,
            'learning' => true,
            'exploration' => true,
            'rating_prior_reviews' => 5,
            'popularity_window_days' => 180,
            'distance_half_km' => 15,
        ];
    }

    /** Named weight sets admins can apply in one click. */
    public static function presets(): array
    {
        $balanced = self::defaults()['weights'];

        return [
            'balanced' => $balanced,
            'quality_first' => array_merge($balanced, [
                'quality' => 10, 'reliability' => 6, 'popularity' => 4, 'price_fit' => 3, 'engagement' => 2,
                'sentiment' => 8,
            ]),
            'popularity' => array_merge($balanced, [
                'popularity' => 10, 'engagement' => 6, 'quality' => 6, 'price_fit' => 3, 'freshness' => 1,
                'sentiment' => 4,
            ]),
            'budget' => array_merge($balanced, [
                'price_fit' => 10, 'quality' => 5, 'popularity' => 4, 'reliability' => 3, 'sentiment' => 4,
            ]),
            'nearby' => array_merge($balanced, [
                'distance' => 10, 'price_fit' => 4, 'popularity' => 4, 'sentiment' => 4,
            ]),
        ];
    }

    /** Stored settings merged over the defaults (cached). Never throws. */
    public static function get(): array
    {
        try {
            $stored = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                $row = DB::table('app_settings')->where('key', self::KEY)->value('value');
                $decoded = $row ? json_decode($row, true) : null;
                return is_array($decoded) ? $decoded : [];
            });
        } catch (Throwable $e) {
            report($e);
            $stored = [];
        }

        return self::normalize(is_array($stored) ? $stored : []);
    }

    /**
     * Merge a partial update over the current settings, clamp every value, persist it and clear the cache.
     * A partial may also be ['preset' => 'quality_first'] to replace the weights with a preset.
     */
    public static function save(array $partial): array
    {
        $current = self::get();

        if (isset($partial['preset']) && is_string($partial['preset'])) {
            $presets = self::presets();
            if (isset($presets[$partial['preset']])) {
                $partial['weights'] = array_merge($presets[$partial['preset']], (array) ($partial['weights'] ?? []));
            }
            unset($partial['preset']);
        }

        if (isset($partial['weights']) && is_array($partial['weights'])) {
            $partial['weights'] = array_merge($current['weights'], $partial['weights']);
        }

        $settings = self::normalize(array_merge($current, $partial));

        $now = now();
        DB::table('app_settings')->upsert(
            [['key' => self::KEY, 'value' => json_encode($settings), 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'updated_at']
        );

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $e) {
            report($e);
        }

        return $settings;
    }

    /** Clamp/cast arbitrary input into a complete, valid settings array (unknown keys dropped). */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $weightsIn = is_array($input['weights'] ?? null) ? $input['weights'] : [];

        $weights = [];
        foreach (self::SIGNALS as $signal) {
            $value = $weightsIn[$signal] ?? $defaults['weights'][$signal];
            $weights[$signal] = is_numeric($value) ? self::clampInt($value, 0, 10) : $defaults['weights'][$signal];
        }

        $int = fn(string $key, int $min, int $max) => is_numeric($input[$key] ?? null)
            ? self::clampInt($input[$key], $min, $max)
            : $defaults[$key];
        $bool = fn(string $key) => array_key_exists($key, $input)
            ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $defaults[$key]
            : $defaults[$key];

        return [
            'weights' => $weights,
            'featured_boost' => $int('featured_boost', 0, 20),
            'personalization' => $bool('personalization'),
            'learning' => $bool('learning'),
            'exploration' => $bool('exploration'),
            'rating_prior_reviews' => $int('rating_prior_reviews', 0, 100),
            'popularity_window_days' => $int('popularity_window_days', 7, 730),
            'distance_half_km' => $int('distance_half_km', 1, 500),
        ];
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) round((float) $value)));
    }
}
