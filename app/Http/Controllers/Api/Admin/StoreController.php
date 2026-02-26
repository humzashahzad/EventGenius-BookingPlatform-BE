<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Store::with('owner:id,name,email');

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name','like','%'.$request->search.'%')
                  ->orWhere('city','like','%'.$request->search.'%');
            });
        }

        $stores = $query->withCount(['venues','bookings'])->orderBy('created_at','desc')->paginate($request->get('per_page',15));
        return response()->json(['success' => true, 'data' => $stores]);
    }

    public function show(string $id): JsonResponse
    {
        $store = Store::with(['owner','venues'])->withCount(['venues','bookings'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $store]);
    }

    public function approve(string $id): JsonResponse
    {
        $store = Store::with('owner')->findOrFail($id);
        $store->update(['status' => 'approved']);

        if ($store->owner) {
            NotificationService::notifyStoreOwner(
                $store->owner->id, 'store_approved', 'Store Approved!',
                "Congratulations! Your store \"{$store->name}\" has been approved. You can now list venues and accept bookings.",
                ['store_id' => $store->id, 'store_name' => $store->name]
            );
        }

        return response()->json(['success' => true, 'data' => $store, 'message' => 'Store approved.']);
    }

    public function suspend(string $id): JsonResponse
    {
        $store = Store::with('owner')->findOrFail($id);
        $store->update(['status' => 'suspended']);

        if ($store->owner) {
            NotificationService::notifyStoreOwner(
                $store->owner->id, 'store_suspended', 'Store Suspended',
                "Your store \"{$store->name}\" has been suspended. Please contact support for more information.",
                ['store_id' => $store->id, 'store_name' => $store->name]
            );
        }

        return response()->json(['success' => true, 'data' => $store, 'message' => 'Store suspended.']);
    }

    public function destroy(string $id): JsonResponse
    {
        $store = Store::findOrFail($id);
        $store->delete();
        return response()->json(['success' => true, 'message' => 'Store deleted.']);
    }
}
