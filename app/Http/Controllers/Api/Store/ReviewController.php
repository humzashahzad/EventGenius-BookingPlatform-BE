<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\NotificationService;
use App\Services\Reviews\ReviewInsightReport;
use App\Services\Reviews\VenueReviewAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** GET /store/reviews — reviews on the authenticated owner's venues. */
    public function index(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $query = Review::with(['client:id,name,avatar', 'venue:id,name', 'booking:id,booking_number,event_date'])
            ->whereHas('venue', fn ($q) => $q->where('store_id', $store->id));

        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->venue_id);
        }
        if ($request->get('replied') === 'no') {
            $query->whereNull('store_reply');
        } elseif ($request->get('replied') === 'yes') {
            $query->whereNotNull('store_reply');
        }

        return response()->json([
            'success' => true,
            'data'    => $query->latest()->paginate(min((int) $request->get('per_page', 10), 50)),
        ]);
    }

    /**
     * GET /store/reviews/insights — AI review insights per venue of the owner's store:
     * summary, monthly trend and recent negative reviews still waiting for a reply.
     */
    public function insights(Request $request, ReviewInsightReport $report, VenueReviewAggregator $aggregator): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venues = DB::table('venues')->where('store_id', $store->id)->whereNull('deleted_at')
            ->when($request->filled('venue_id'), fn ($q) => $q->where('id', (int) $request->venue_id))
            ->orderBy('name')->get(['id', 'name', 'city', 'avg_rating', 'total_reviews']);
        $ids = $venues->pluck('id')->map(fn ($id) => (int) $id)->all();

        $months = min(24, max(1, (int) $request->get('months', 12)));
        $trend = $report->monthlyTrend($ids, $months);
        $needsReply = $report->needsReply($ids);
        $rows = DB::table('venue_review_insights')->whereIn('venue_id', $ids)->get()->keyBy('venue_id');
        $global = $aggregator->globalMean();

        return response()->json([
            'success' => true,
            'data' => [
                'overall' => $report->combinedSummary($ids),
                'venues' => $venues->map(fn ($v) => [
                    'venue_id' => (int) $v->id,
                    'name' => $v->name,
                    'city' => $v->city,
                    'avg_rating' => (float) $v->avg_rating,
                    'total_reviews' => (int) $v->total_reviews,
                    'summary' => $aggregator->summary((int) $v->id, $rows[$v->id] ?? null, $global),
                    'trend' => $trend[(int) $v->id] ?? [],
                    'needs_reply' => $needsReply[(int) $v->id] ?? [],
                ])->values(),
            ],
        ]);
    }

    /** POST /store/reviews/{id}/reply — set or update the owner's public reply. */
    public function reply(Request $request, string $id): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $review = Review::with('venue:id,name,store_id')
            ->whereHas('venue', fn ($q) => $q->where('store_id', $store->id))
            ->findOrFail($id);

        $validated = $request->validate(['reply' => 'required|string|max:2000']);

        $review->update([
            'store_reply'      => $validated['reply'],
            'store_replied_at' => now(),
        ]);

        NotificationService::notifyClient(
            $review->client_id,
            'review_reply',
            'The venue replied to your review',
            "{$store->name} replied to your review of \"{$review->venue->name}\".",
            ['review_id' => $review->id, 'venue_id' => $review->venue_id]
        );

        return response()->json([
            'success' => true,
            'data'    => $review->load('client:id,name,avatar'),
            'message' => 'Reply saved.',
        ]);
    }
}
