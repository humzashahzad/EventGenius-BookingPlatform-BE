<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get all users (for chat/messaging purposes)
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $query = User::where('id', '!=', $currentUser->id)
            ->select('id', 'name', 'email', 'role', 'avatar')
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%' . $term . '%')
                  ->orWhere('email', 'like', '%' . $term . '%');
            });
        }

        $users = $query->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }
}
