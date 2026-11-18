<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\VenueAmenity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmenityController extends Controller
{
    /** Venue owned by the authenticated store owner, or 404. */
    private function ownedVenue(Request $request, string $venueId): Venue
    {
        $storeId = $request->user()->store?->id;
        abort_if(!$storeId, 404, 'Store not found.');

        return Venue::where('store_id', $storeId)->findOrFail($venueId);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        return [
            'name'    => "$req|string|max:255",
            'icon'    => 'nullable|string|max:100',
            'is_paid' => 'sometimes|boolean',
            'price'   => 'nullable|numeric|min:0|max:999999.99|required_if:is_paid,true,1',
        ];
    }

    private function normalise(array $data): array
    {
        if (array_key_exists('is_paid', $data) && !$data['is_paid']) {
            $data['price'] = null;
        }
        return $data;
    }

    public function index(Request $request, string $id): JsonResponse
    {
        $venue = $this->ownedVenue($request, $id);
        return response()->json(['success' => true, 'data' => $venue->amenities()->orderBy('name')->get()]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $venue = $this->ownedVenue($request, $id);
        $data  = $this->normalise($request->validate($this->rules()));
        $data['is_paid'] = (bool) ($data['is_paid'] ?? false);

        $amenity = $venue->amenities()->create($data);

        return response()->json(['success' => true, 'data' => $amenity, 'message' => 'Amenity added.'], 201);
    }

    public function update(Request $request, string $venueId, string $amenityId): JsonResponse
    {
        $venue   = $this->ownedVenue($request, $venueId);
        $amenity = VenueAmenity::where('venue_id', $venue->id)->findOrFail($amenityId);

        $data = $this->normalise($request->validate($this->rules(true)));
        $paid = $data['is_paid'] ?? $amenity->is_paid;
        $price = array_key_exists('price', $data) ? $data['price'] : $amenity->price;
        if ($paid && $price === null) {
            return response()->json([
                'success' => false,
                'message' => 'A paid amenity needs a price.',
                'errors'  => ['price' => ['A paid amenity needs a price.']],
            ], 422);
        }

        $amenity->update($data);

        return response()->json(['success' => true, 'data' => $amenity->fresh(), 'message' => 'Amenity updated.']);
    }

    public function destroy(Request $request, string $venueId, string $amenityId): JsonResponse
    {
        $venue   = $this->ownedVenue($request, $venueId);
        $amenity = VenueAmenity::where('venue_id', $venue->id)->findOrFail($amenityId);
        $amenity->delete();

        return response()->json(['success' => true, 'message' => 'Amenity removed.']);
    }
}
