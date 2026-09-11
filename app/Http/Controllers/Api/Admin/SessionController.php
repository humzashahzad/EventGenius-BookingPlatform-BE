<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private JwtService $jwt) {}

    /**
     * List all sessions with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = UserSession::with('user:id,name,email,role,avatar');

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_revoked', false)->where('expires_at', '>', now());
            } elseif ($request->status === 'revoked') {
                $query->where('is_revoked', true);
            } elseif ($request->status === 'expired') {
                $query->where('is_revoked', false)->where('expires_at', '<=', now());
            }
        }

        // Search by user name/email
        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->whereHas('user', fn($q) => $q->where('role', $request->role));
        }

        $sessions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    /**
     * Revoke a specific session by ID.
     */
    public function revoke(string $id): JsonResponse
    {
        $session = UserSession::findOrFail($id);

        if ($session->is_revoked) {
            return response()->json([
                'success' => false,
                'message' => 'Session is already revoked.',
            ], 422);
        }

        $session->update(['is_revoked' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Session revoked successfully. User will be logged out on next request.',
        ]);
    }

    /**
     * Revoke all active sessions for a specific user.
     */
    public function revokeAllForUser(string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        $revokedCount = UserSession::where('user_id', $userId)
            ->where('is_revoked', false)
            ->count();

        $this->jwt->revokeAllForUser($userId);

        return response()->json([
            'success' => true,
            'message' => "All {$revokedCount} active session(s) revoked for {$user->name}.",
            'data'    => ['revoked_count' => $revokedCount],
        ]);
    }
}
