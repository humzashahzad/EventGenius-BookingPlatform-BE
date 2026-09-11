<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user  = $request->user();
        $store = $user->store;
        return response()->json([
            'success' => true,
            'data' => [
                'user'  => $user,
                'store' => $store,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'phone'        => 'sometimes|nullable|string|max:20',
            'store_name'   => 'sometimes|string|max:255',
            'description'  => 'nullable|string',
            'address'      => 'nullable|string',
            'city'         => 'nullable|string',
            'state'        => 'nullable|string',
            'store_phone'  => 'nullable|string|max:20',
            'store_email'  => 'nullable|email',
            'website'      => 'nullable|url',
            'business_hours'=> 'nullable|array',
            'social_links' => 'nullable|array',
        ]);

        // Update user fields
        $userFields = array_intersect_key($validated, array_flip(['name', 'phone']));
        if (!empty($userFields)) {
            $user->update($userFields);
        }

        // Update or create store
        $storeFields = [];
        if (isset($validated['store_name'])) {
            $storeFields['name'] = $validated['store_name'];
            $storeFields['slug'] = Str::slug($validated['store_name']) . '-' . $user->id;
        }
        foreach (['description', 'address', 'city', 'state', 'website', 'business_hours', 'social_links'] as $field) {
            if (array_key_exists($field, $validated)) {
                $storeFields[$field] = $validated[$field];
            }
        }
        if (isset($validated['store_phone'])) $storeFields['phone'] = $validated['store_phone'];
        if (isset($validated['store_email'])) $storeFields['email'] = $validated['store_email'];

        if (!empty($storeFields)) {
            $store = Store::updateOrCreate(
                ['user_id' => $user->id],
                array_merge($storeFields, ['user_id' => $user->id])
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user'  => $user->fresh(),
                'store' => $user->store()->first(),
            ],
            'message' => 'Profile updated.',
        ]);
    }
}
