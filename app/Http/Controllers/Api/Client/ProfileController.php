<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $request->user()]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'phone'    => 'sometimes|nullable|string|max:20',
            'password' => 'sometimes|string|min:8|confirmed',
            'current_password' => 'required_with:password|string',
        ], [
            'current_password.required_with' => 'Please enter your current password to set a new one.',
        ]);

        if (isset($validated['password'])) {
            if (!Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Your current password is incorrect.'],
                ]);
            }
            $validated['password'] = bcrypt($validated['password']);
        }
        unset($validated['current_password']);

        $user->update($validated);

        return response()->json(['success' => true, 'data' => $user, 'message' => 'Profile updated.']);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => 'required|image|max:2048']);
        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);
        return response()->json([
            'success' => true,
            'data'    => $user,
            'message' => 'Avatar updated.',
        ]);
    }
}
