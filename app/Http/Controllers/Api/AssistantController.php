<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\ConversationStore;
use App\Services\Assistant\DbCatalog;
use App\Services\Search\VenueSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Ask EventGenius": chat-style venue search (see docs/search-assistant.md).
 * Public with optional login; throttled by the `assistant` rate limiter.
 */
class AssistantController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $this->engine()->start(VenueSearch::resolveUser($request));
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'nullable|string|uuid',
            'message' => 'required|string|min:1|max:500',
        ]);
        $message = trim((string) $validated['message']);
        if ($message === '') {
            return response()->json(['success' => false, 'message' => 'Please type a message.'], 422);
        }

        try {
            $data = $this->engine()->handle($validated['conversation_id'] ?? null, $message, VenueSearch::resolveUser($request));
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Sorry, something went wrong on our side. Please try again.',
            ], 500);
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function engine(): AssistantEngine
    {
        return new AssistantEngine(new DbCatalog(), new ConversationStore(Cache::store()));
    }
}
