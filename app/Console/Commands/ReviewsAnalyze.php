<?php

namespace App\Console\Commands;

use App\Models\Review;
use App\Services\Reviews\ReviewInsightService;
use App\Services\Reviews\VenueReviewAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Analyse reviews that have no insight yet (covers reviews inserted directly into the DB, e.g. by
 * demo:grow, and anything the queued job missed). `--all` re-analyses everything, e.g. after a
 * model/lexicon update. Venue aggregates are refreshed for touched venues, and fully every 6 hours
 * because recency weights decay over time.
 */
class ReviewsAnalyze extends Command
{
    private const FULL_REFRESH_KEY = 'review-insights:full-refresh';

    protected $signature = 'reviews:analyze
        {--limit=200 : Maximum reviews per run (ignored with --all)}
        {--all : Re-analyse every review, not only new ones}
        {--refresh : Only rebuild the venue aggregates}';

    protected $description = 'Run AI sentiment/aspect analysis on new reviews and refresh venue review insights';

    public function handle(ReviewInsightService $service, VenueReviewAggregator $aggregator): int
    {
        if ($this->option('refresh')) {
            $this->info('Refreshed ' . $aggregator->recomputeAll() . ' venue aggregate(s).');
            Cache::put(self::FULL_REFRESH_KEY, now()->toIso8601String(), now()->addHours(6));
            return self::SUCCESS;
        }

        $analyzer = $service->analyzer();
        if (($why = $analyzer->missing()) !== null) {
            // Not an error for the scheduler: reviews keep working, analysis catches up once installed.
            Log::warning("reviews:analyze skipped: {$why}");
            $this->warn("Skipped: {$why}");
            return self::SUCCESS;
        }

        $all = (bool) $this->option('all');
        $limit = max(1, (int) $this->option('limit'));
        $query = $all ? Review::query() : ReviewInsightService::pendingQuery();
        $total = (clone $query)->count();
        $todo = $all ? $total : min($total, $limit);
        if ($todo === 0) {
            $this->refreshIfStale($aggregator);
            $this->line('No reviews to analyse.');
            return self::SUCCESS;
        }

        $started = microtime(true);
        $done = 0;
        $venues = 0;
        $chunk = max(1, (int) config('review_ai.batch_size', 50)) * 4;
        $lastId = 0;
        while ($done < $todo) {
            $reviews = (clone $query)->where('id', '>', $lastId)->orderBy('id')->limit(min($chunk, $todo - $done))->get();
            if ($reviews->isEmpty()) {
                break;
            }
            $lastId = (int) $reviews->last()->id;
            $res = $service->analyze($reviews);
            if ($res['skipped']) {
                $this->warn('Analyser failed — see the log; stopping this run.');
                break;
            }
            $done += $res['analysed'];
            $venues += $res['venues'];
            if ($res['analysed'] === 0) {
                break;
            }
        }
        $secs = microtime(true) - $started;

        $this->refreshIfStale($aggregator);
        $this->info(sprintf('Analysed %d review(s) in %.1fs (%.0f ms/review), %d venue aggregate update(s); %d still pending.',
            $done, $secs, $done ? 1000 * $secs / $done : 0, $venues, ReviewInsightService::pendingQuery()->count()));

        return self::SUCCESS;
    }

    private function refreshIfStale(VenueReviewAggregator $aggregator): void
    {
        if (!Cache::has(self::FULL_REFRESH_KEY)) {
            $aggregator->recomputeAll();
            Cache::put(self::FULL_REFRESH_KEY, now()->toIso8601String(), now()->addHours(6));
        }
    }
}
