<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreLandingPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    /**
     * Get the current store's landing page.
     */
    public function show(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $landing = $store->landingPage;

        return response()->json([
            'success' => true,
            'data' => $landing,
        ]);
    }

    /**
     * Create or update the store's landing page.
     */
    public function upsert(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $validated = $request->validate([
            'tagline'      => 'nullable|string|max:255',
            'about_text'   => 'nullable|string',
            'services'     => 'nullable|array',
            'services.*.title' => 'required_with:services|string|max:255',
            'services.*.description' => 'nullable|string',
            'faq'          => 'nullable|array',
            'faq.*.question' => 'required_with:faq|string|max:500',
            'faq.*.answer'   => 'required_with:faq|string',
            'social_links' => 'nullable|array',
            'testimonials' => 'nullable|array',
            'testimonials.*.name'  => 'required_with:testimonials|string|max:255',
            'testimonials.*.quote' => 'required_with:testimonials|string',
            'testimonials.*.rating' => 'nullable|integer|min:1|max:5',
            'gallery'      => 'nullable|array',
        ]);

        $landing = StoreLandingPage::updateOrCreate(
            ['store_id' => $store->id],
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => $landing,
            'message' => 'Landing page updated.',
        ]);
    }

    /**
     * Upload hero image.
     */
    public function uploadHero(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $request->validate([
            'hero_image' => 'required|image|max:4096',
        ]);

        $path = $request->file('hero_image')->store("stores/{$store->id}/landing", 'public');

        $landing = StoreLandingPage::updateOrCreate(
            ['store_id' => $store->id],
            ['hero_image' => $path]
        );

        return response()->json([
            'success' => true,
            'data' => $landing,
            'message' => 'Hero image uploaded.',
        ]);
    }

    /**
     * Upload gallery images.
     */
    public function uploadGallery(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $request->validate([
            'images'   => 'required|array|min:1|max:10',
            'images.*' => 'image|max:4096',
        ]);

        $landing = StoreLandingPage::firstOrCreate(['store_id' => $store->id]);
        $gallery = $landing->gallery ?? [];

        foreach ($request->file('images') as $file) {
            $path = $file->store("stores/{$store->id}/landing/gallery", 'public');
            $gallery[] = $path;
        }

        $landing->update(['gallery' => $gallery]);

        return response()->json([
            'success' => true,
            'data' => $landing,
            'message' => 'Gallery images uploaded.',
        ]);
    }
}
