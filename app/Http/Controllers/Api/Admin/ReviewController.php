<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Reviews\ReviewAnalyzer;
use App\Services\Reviews\ReviewInsightReport;
use App\Services\Reviews\ReviewInsightService;
use App\Services\Reviews\VenueReviewAggregator;
use Illuminate\Support\Facades\DB;
use App\Services\VenueRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** GET /admin/reviews */
    public function index(Request $request): JsonResponse
    {
        $query = Review::with(['client:id,name,email,avatar', 'venue:id,name,store_id', 'venue.store:id,name', 'insight']);

        if ($request->filled('approved')) {
            $query->where('is_approved', $request->boolean('approved'));
        }
        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->venue_id);
        }
        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->rating);
        }
        // AI insight filters: ?sentiment=negative, ?flag=spam, ?flagged=1 (any flag).
        if (in_array($request->get('sentiment'), ['positive', 'neutral', 'negative'], true)) {
            $query->whereHas('insight', fn ($i) => $i->where('label', $request->get('sentiment')));
        }
        if ($request->filled('flag') && array_key_exists($request->get('flag'), ReviewInsightReport::FLAG_REASONS)) {
            $query->whereHas('insight', fn ($i) => $i->whereJsonContains('flags', $request->get('flag')));
        } elseif ($request->boolean('flagged')) {
            $query->whereHas('insight', fn ($i) => $i->whereJsonLength('flags', '>', 0));
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', $s)
                  ->orWhere('comment', 'like', $s)
                  ->orWhereHas('venue', fn ($v) => $v->where('name', 'like', $s))
                  ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $s));
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $query->latest()->paginate(min((int) $request->get('per_page', 15), 100)),
        ]);
    }

    /** GET /admin/reviews/insights — platform overview of the AI review analysis. */
    public function insights(Request $request, ReviewAnalyzer $analyzer, ReviewInsightReport $report, VenueReviewAggregator $aggregator): JsonResponse
    {
        $counted = ReviewInsightReport::countedQuery();
        $dist = (clone $counted)->selectRaw("SUM(review_insights.label = 'positive') AS positive,
                SUM(review_insights.label = 'neutral') AS neutral, SUM(review_insights.label = 'negative') AS negative,
                AVG(review_insights.sentiment) AS avg, COUNT(*) AS n")->first();
        $languages = (clone $counted)->groupBy('review_insights.language')
            ->selectRaw('review_insights.language, COUNT(*) AS n')->pluck('n', 'language');

        $flagCounts = [];
        foreach (array_keys(ReviewInsightReport::FLAG_REASONS) as $flag) {
            $flagCounts[$flag] = DB::table('review_insights')->join('reviews', 'reviews.id', '=', 'review_insights.review_id')
                ->whereNull('reviews.deleted_at')->whereJsonContains('review_insights.flags', $flag)->count();
        }

        $flagFilter = $request->get('flag');
        $flaggedQuery = Review::with(['client:id,name,email', 'venue:id,name', 'insight'])
            ->whereHas('insight', function ($i) use ($flagFilter) {
                if (is_string($flagFilter) && array_key_exists($flagFilter, ReviewInsightReport::FLAG_REASONS)) {
                    $i->whereJsonContains('flags', $flagFilter);
                } else {
                    // Everything except reviews whose only flag is "very_short".
                    $i->whereJsonLength('flags', '>', 0)
                      ->where(fn ($q) => $q->whereJsonLength('flags', '>', 1)->orWhereJsonDoesntContain('flags', 'very_short'));
                }
            })
            ->latest()->limit(min(100, max(1, (int) $request->get('limit', 50))));
        $flagged = $flaggedQuery->get()->map(fn (Review $r) => [
            'review_id' => $r->id,
            'venue' => $r->venue ? ['id' => $r->venue->id, 'name' => $r->venue->name] : null,
            'client' => $r->client ? ['id' => $r->client->id, 'name' => $r->client->name] : null,
            'rating' => $r->rating,
            'title' => $r->title,
            'comment' => $r->comment,
            'is_approved' => $r->is_approved,
            'sentiment' => (int) round(50 * ($r->insight->sentiment + 1)),
            'label' => $r->insight->label,
            'flags' => $r->insight->flags ?? [],
            'reasons' => ReviewInsightReport::reasons($r->insight->flags ?? [], $r->rating, $r->insight->sentiment),
            'created_at' => $r->created_at?->toIso8601String(),
        ]);

        $versions = DB::table('review_insights')->groupBy('model_version')
            ->selectRaw('model_version, COUNT(*) AS n, MAX(analyzed_at) AS last')->orderByDesc('n')->get()
            ->map(fn ($v) => ['model_version' => $v->model_version, 'reviews' => (int) $v->n, 'last_analyzed_at' => $v->last]);

        $global = $aggregator->globalMean();
        $venues = DB::table('venue_review_insights')->join('venues', 'venues.id', '=', 'venue_review_insights.venue_id')
            ->where('venue_review_insights.text_count', '>=', (int) config('review_ai.min_reviews', 3))
            ->whereNull('venues.deleted_at')
            ->get(['venues.id', 'venues.name', 'venues.city', 'venue_review_insights.*'])
            ->map(fn ($v) => [
                'venue_id' => (int) $v->venue_id, 'name' => $v->name, 'city' => $v->city,
                'analysed_count' => (int) $v->analysed_count,
                'sentiment' => (int) round(50 * (VenueReviewAggregator::smooth((float) $v->weight_sum, (float) $v->weighted_sentiment, $global, (float) config('review_ai.prior_reviews', 2)) + 1)),
                'negative_count' => (int) $v->negative_count,
            ])->sortBy('sentiment')->values();

        $n = (int) ($dist->n ?? 0);
        return response()->json([
            'success' => true,
            'data' => [
                'model' => $analyzer->status($request->boolean('refresh_status')) + ['versions' => $versions],
                'pending_analysis' => ReviewInsightService::pendingQuery()->count(),
                'totals' => [
                    'reviews' => Review::count(),
                    'analysed' => DB::table('review_insights')->join('reviews', 'reviews.id', '=', 'review_insights.review_id')->whereNull('reviews.deleted_at')->count(),
                    'counted' => $n,
                ],
                'average_sentiment' => $n ? (int) round(50 * ((float) $dist->avg + 1)) : null,
                'sentiment_distribution' => [
                    'positive' => (int) ($dist->positive ?? 0),
                    'neutral' => (int) ($dist->neutral ?? 0),
                    'negative' => (int) ($dist->negative ?? 0),
                ],
                'languages' => $languages,
                'aspects' => $report->platformAspects(),
                'flag_counts' => $flagCounts,
                'flagged' => $flagged,
                'venues_lowest_sentiment' => $venues->take(5)->values(),
                'venues_highest_sentiment' => $venues->reverse()->take(5)->values(),
            ],
        ]);
    }

    /** PATCH /admin/reviews/{id}/toggle-approval */
    public function toggleApproval(string $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->update(['is_approved' => !$review->is_approved]);
        VenueRatingService::recalculate($review->venue_id);
        app(ReviewInsightService::class)->refreshVenue($review->venue_id);

        return response()->json([
            'success' => true,
            'data'    => $review,
            'message' => $review->is_approved ? 'Review approved.' : 'Review hidden.',
        ]);
    }

    /** DELETE /admin/reviews/{id} (soft delete) */
    public function destroy(string $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $venueId = $review->venue_id;
        $review->delete();
        VenueRatingService::recalculate($venueId);
        app(ReviewInsightService::class)->refreshVenue($venueId);

        return response()->json(['success' => true, 'message' => 'Review deleted.']);
    }
}
