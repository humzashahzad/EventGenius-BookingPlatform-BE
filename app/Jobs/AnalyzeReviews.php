<?php

namespace App\Jobs;

use App\Services\Reviews\ReviewInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyse freshly submitted reviews in the background (sentiment, aspects, flags) and refresh
 * the venue aggregate, so the next search already reflects the feedback.
 * Reviews missed here (queue down, analyser missing) are picked up by `reviews:analyze`.
 */
class AnalyzeReviews implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public int $backoff = 60;

    /** @param int[] $reviewIds */
    public function __construct(public array $reviewIds)
    {
        if ($queue = config('review_ai.queue')) {
            $this->onQueue($queue);
        }
    }

    public function handle(ReviewInsightService $service): void
    {
        $service->analyze($this->reviewIds);
    }

    /** Queue + analyser problems must never surface to the customer: dispatch defensively. */
    public static function dispatchSafely(array $reviewIds): void
    {
        try {
            static::dispatch(array_values(array_map('intval', $reviewIds)))->afterCommit();
        } catch (Throwable $e) {
            Log::warning('Could not queue review analysis: ' . $e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Review analysis job failed (reviews:analyze will retry): ' . ($e?->getMessage() ?? 'unknown'));
    }
}
