<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Venue;
use App\Models\VenueImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VenueController extends Controller
{
    private function getStore(Request $request): ?Store
    {
        return $request->user()->store;
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venues = Venue::with(['images' => fn($q) => $q->where('is_primary', true)])
            ->where('store_id', $store->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json(['success' => true, 'data' => $venues]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }
        if ($store->status !== 'approved') {
            return response()->json(['success' => false, 'message' => 'Your store must be approved to add venues.'], 403);
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'address'        => 'required|string',
            'city'           => 'required|string',
            'state'          => 'nullable|string',
            'capacity_min'   => 'sometimes|integer|min:1',
            'capacity_max'   => 'required|integer|min:1',
            'area_sqft'      => 'nullable|integer',
            'floors'         => 'sometimes|integer|min:1',
            'price_per_head'  => 'required|numeric|min:0',
            'price_per_hour'  => 'nullable|numeric|min:0',
            'price_per_day'   => 'nullable|numeric|min:0',
            'price_per_event' => 'nullable|numeric|min:0',
            'pricing_type'    => 'nullable|in:per_hour,per_day,per_event,per_head,negotiable',
            'dynamic_pricing' => 'sometimes|boolean',
            'event_types'     => 'nullable|array',
            'event_types.*'   => 'string',
            'operating_start' => 'nullable|date_format:H:i',
            'operating_end'   => 'nullable|date_format:H:i',
            'latitude'        => 'nullable|numeric',
            'longitude'       => 'nullable|numeric',
            'country'         => 'sometimes|string|max:255',
        ]);

        $validated['pricing_type'] = 'per_head';
        $validated['store_id'] = $store->id;
        $validated['slug']     = Str::slug($validated['name']) . '-' . Str::random(6);

        $venue = Venue::create($validated);
        $venue->load(['images', 'amenities']);

        return response()->json(['success' => true, 'data' => $venue, 'message' => 'Venue created.'], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::with(['images', 'amenities'])
            ->where('store_id', $store->id)
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $venue]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::where('store_id', $store->id)->findOrFail($id);

        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'description'    => 'nullable|string',
            'address'        => 'sometimes|string',
            'city'           => 'sometimes|string',
            'state'          => 'nullable|string',
            'capacity_min'   => 'sometimes|integer|min:1',
            'capacity_max'   => 'sometimes|integer|min:1',
            'area_sqft'      => 'nullable|integer',
            'floors'         => 'sometimes|integer|min:1',
            'price_per_head'  => 'sometimes|numeric|min:0',
            'price_per_hour'  => 'nullable|numeric|min:0',
            'price_per_day'   => 'nullable|numeric|min:0',
            'price_per_event' => 'nullable|numeric|min:0',
            'pricing_type'    => 'nullable|in:per_hour,per_day,per_event,per_head,negotiable',
            'dynamic_pricing'=> 'sometimes|boolean',
            'event_types'     => 'nullable|array',
            'status'          => 'sometimes|in:active,inactive',
            'operating_start' => 'nullable|date_format:H:i',
            'operating_end'   => 'nullable|date_format:H:i',
            'latitude'        => 'nullable|numeric',
            'longitude'       => 'nullable|numeric',
            'country'         => 'sometimes|string|max:255',
        ]);

        if (
            array_key_exists('price_per_head', $validated)
            || array_key_exists('pricing_type', $validated)
            || $venue->price_per_head !== null
        ) {
            $validated['pricing_type'] = 'per_head';
        }

        $venue->update($validated);
        $venue->load(['images', 'amenities']);

        return response()->json(['success' => true, 'data' => $venue, 'message' => 'Venue updated.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::where('store_id', $store->id)->findOrFail($id);
        $venue->delete();

        return response()->json(['success' => true, 'message' => 'Venue deleted.']);
    }

    public function uploadImages(Request $request, string $id): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::where('store_id', $store->id)->findOrFail($id);

        $request->validate([
            'images'    => 'required|array|min:1|max:10',
            'images.*'  => 'image|max:4096',
        ]);

        $uploaded = [];
        $existingCount = $venue->images()->count();

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store("venues/{$venue->id}", 'public');
            $image = VenueImage::create([
                'venue_id'   => $venue->id,
                'path'       => $path,
                'alt_text'   => $venue->name,
                'is_primary' => ($existingCount === 0 && $index === 0),
                'sort_order' => $existingCount + $index,
            ]);
            $uploaded[] = $image;
        }

        // Set thumbnail if first upload
        if ($existingCount === 0 && !empty($uploaded)) {
            $venue->update(['thumbnail' => $uploaded[0]->path]);
        }

        return response()->json(['success' => true, 'data' => $uploaded, 'message' => 'Images uploaded.']);
    }

    public function deleteImage(Request $request, string $venueId, string $imageId): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::where('store_id', $store->id)->findOrFail($venueId);
        $image = VenueImage::where('venue_id', $venue->id)->findOrFail($imageId);

        \Illuminate\Support\Facades\Storage::disk('public')->delete($image->path);
        $wasPrimary = $image->is_primary;
        $image->delete();

        // If deleted image was primary, promote next image
        if ($wasPrimary) {
            $next = VenueImage::where('venue_id', $venue->id)->orderBy('sort_order')->first();
            if ($next) {
                $next->update(['is_primary' => true]);
                $venue->update(['thumbnail' => $next->path]);
            } else {
                $venue->update(['thumbnail' => null]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Image deleted.']);
    }

    public function setPrimaryImage(Request $request, string $venueId, string $imageId): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::where('store_id', $store->id)->findOrFail($venueId);
        $image = VenueImage::where('venue_id', $venue->id)->findOrFail($imageId);

        VenueImage::where('venue_id', $venue->id)->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);
        $venue->update(['thumbnail' => $image->path]);

        return response()->json(['success' => true, 'data' => $image, 'message' => 'Primary image updated.']);
    }

    public function reorderImages(Request $request, string $venueId): JsonResponse
    {
        $store = $this->getStore($request);
        if (!$store) {
            return response()->json(['success' => false, 'message' => 'Store not found.'], 404);
        }

        $venue = Venue::where('store_id', $store->id)->findOrFail($venueId);

        $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        foreach ($request->order as $sortOrder => $imageId) {
            VenueImage::where('venue_id', $venue->id)
                ->where('id', $imageId)
                ->update(['sort_order' => $sortOrder]);
        }

        $images = VenueImage::where('venue_id', $venue->id)->orderBy('sort_order')->get();

        return response()->json(['success' => true, 'data' => $images, 'message' => 'Images reordered.']);
    }
}
