<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * List all locations as a flat list with parent info.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Location::with('parent:id,name,level');

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        $locations = $query->orderBy('level')->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $locations]);
    }

    /**
     * Get locations as a nested tree structure.
     */
    public function tree(): JsonResponse
    {
        $countries = Location::where('level', 'country')
            ->with(['children' => function ($q) {
                $q->where('level', 'city')
                    ->orderBy('name')
                    ->with(['children' => function ($q2) {
                        $q2->where('level', 'area')->orderBy('name');
                    }]);
            }])
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $countries]);
    }

    /**
     * Public endpoint: active locations as tree for dropdowns.
     */
    public function allowedTree(): JsonResponse
    {
        $countries = Location::where('level', 'country')
            ->where('is_active', true)
            ->with(['children' => function ($q) {
                $q->where('level', 'city')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->with(['children' => function ($q2) {
                        $q2->where('level', 'area')
                            ->where('is_active', true)
                            ->orderBy('name');
                    }]);
            }])
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $countries]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'level'     => 'required|in:country,city,area',
            'parent_id' => 'nullable|exists:locations,id',
            'boundary'  => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        // Validate hierarchy: city needs country parent, area needs city parent
        if ($validated['level'] === 'city' && $validated['parent_id']) {
            $parent = Location::find($validated['parent_id']);
            if (!$parent || $parent->level !== 'country') {
                return response()->json(['success' => false, 'message' => 'City must have a country as parent.'], 422);
            }
        }

        if ($validated['level'] === 'area' && $validated['parent_id']) {
            $parent = Location::find($validated['parent_id']);
            if (!$parent || $parent->level !== 'city') {
                return response()->json(['success' => false, 'message' => 'Area must have a city as parent.'], 422);
            }
        }

        if ($validated['level'] === 'country') {
            $validated['parent_id'] = null;
        }

        $location = Location::create($validated);

        return response()->json(['success' => true, 'data' => $location, 'message' => 'Location created.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $location = Location::with(['parent:id,name,level', 'children:id,parent_id,name,level,is_active'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $location]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $location = Location::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'boundary'  => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $location->update($validated);

        return response()->json(['success' => true, 'data' => $location, 'message' => 'Location updated.']);
    }

    public function destroy(string $id): JsonResponse
    {
        $location = Location::findOrFail($id);

        // Check for children
        if ($location->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a location with children. Remove children first.',
            ], 422);
        }

        $location->delete();

        return response()->json(['success' => true, 'message' => 'Location deleted.']);
    }

    /**
     * Toggle active status.
     */
    public function toggle(string $id): JsonResponse
    {
        $location = Location::findOrFail($id);
        $location->update(['is_active' => !$location->is_active]);

        return response()->json([
            'success' => true,
            'data' => $location,
            'message' => $location->is_active ? 'Location activated.' : 'Location deactivated.',
        ]);
    }
}
