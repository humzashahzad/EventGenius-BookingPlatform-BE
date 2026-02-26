<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Venue::with(['store:id,name,city']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('city', 'like', '%' . $request->search . '%');
            });
        }
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        $venues = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json(['success' => true, 'data' => $venues]);
    }

    public function show(string $id): JsonResponse
    {
        $venue = Venue::with(['store', 'images', 'amenities'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $venue]);
    }

    public function toggleStatus(string $id): JsonResponse
    {
        $venue = Venue::findOrFail($id);
        $newStatus = $venue->status === 'active' ? 'inactive' : 'active';
        $venue->update(['status' => $newStatus]);
        return response()->json([
            'success' => true,
            'data'    => $venue,
            'message' => "Venue status changed to {$newStatus}.",
        ]);
    }
}
