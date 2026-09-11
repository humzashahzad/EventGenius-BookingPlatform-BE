<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Api\Admin\VenueController as AdminVenueController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Api\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Store\VenueController as StoreVenueController;
use App\Http\Controllers\Api\Store\BookingController as StoreBookingController;
use App\Http\Controllers\Api\Store\DashboardController as StoreDashboardController;
use App\Http\Controllers\Api\Store\ProfileController as StoreProfileController;
use App\Http\Controllers\Api\Store\LandingController as StoreLandingController;
use App\Http\Controllers\Api\Client\VenueController as ClientVenueController;
use App\Http\Controllers\Api\Client\BookingController as ClientBookingController;
use App\Http\Controllers\Api\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Api\Client\ProfileController as ClientProfileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ─── Auth (Public) ────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('jwt')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });
});

// ─── Public Categories ───────────────────────────────────────────────────────
Route::get('/categories', [AdminCategoryController::class, 'publicIndex']);

// ─── Public Venue Browsing ────────────────────────────────────────────────────
Route::prefix('venues')->group(function () {
    Route::get('/', [ClientVenueController::class, 'index']);
    Route::get('/{id}', [ClientVenueController::class, 'show']);
});

// ─── Client Routes ────────────────────────────────────────────────────────────
Route::prefix('client')
    ->middleware(['jwt', 'role:client'])
    ->group(function () {
        Route::get('/dashboard', [ClientDashboardController::class, 'index']);

        // Bookings
        Route::get('/bookings', [ClientBookingController::class, 'index']);
        Route::post('/bookings', [ClientBookingController::class, 'store']);
        Route::get('/bookings/{id}', [ClientBookingController::class, 'show']);
        Route::patch('/bookings/{id}/cancel', [ClientBookingController::class, 'cancel']);

        // Profile
        Route::get('/profile', [ClientProfileController::class, 'show']);
        Route::put('/profile', [ClientProfileController::class, 'update']);
        Route::post('/profile/avatar', [ClientProfileController::class, 'updateAvatar']);
    });

// ─── Store Owner Routes ───────────────────────────────────────────────────────
Route::prefix('store')
    ->middleware(['jwt', 'role:store_owner'])
    ->group(function () {
        Route::get('/dashboard', [StoreDashboardController::class, 'index']);

        // Venues CRUD + gallery management
        Route::apiResource('venues', StoreVenueController::class);
        Route::post('/venues/{id}/images', [StoreVenueController::class, 'uploadImages']);
        Route::delete('/venues/{venueId}/images/{imageId}', [StoreVenueController::class, 'deleteImage']);
        Route::patch('/venues/{venueId}/images/{imageId}/set-primary', [StoreVenueController::class, 'setPrimaryImage']);
        Route::post('/venues/{venueId}/images/reorder', [StoreVenueController::class, 'reorderImages']);

        // Bookings (received)
        Route::get('/bookings', [StoreBookingController::class, 'index']);
        Route::get('/bookings/{id}', [StoreBookingController::class, 'show']);
        Route::patch('/bookings/{id}/confirm', [StoreBookingController::class, 'confirm']);
        Route::patch('/bookings/{id}/reject', [StoreBookingController::class, 'reject']);

        // Profile
        Route::get('/profile', [StoreProfileController::class, 'show']);
        Route::put('/profile', [StoreProfileController::class, 'update']);

        // Landing Page
        Route::get('/landing', [StoreLandingController::class, 'show']);
        Route::put('/landing', [StoreLandingController::class, 'upsert']);
        Route::post('/landing/hero', [StoreLandingController::class, 'uploadHero']);
        Route::post('/landing/gallery', [StoreLandingController::class, 'uploadGallery']);
    });

// ─── Admin Routes ─────────────────────────────────────────────────────────────
Route::prefix('admin')
    ->middleware(['jwt', 'role:admin'])
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // Users
        Route::apiResource('users', AdminUserController::class);
        Route::patch('/users/{id}/toggle-status', [AdminUserController::class, 'toggleStatus']);

        // Session management
        Route::get('/sessions', [AdminSessionController::class, 'index']);
        Route::delete('/sessions/{id}', [AdminSessionController::class, 'revoke']);
        Route::delete('/sessions/user/{userId}/all', [AdminSessionController::class, 'revokeAllForUser']);

        // Stores (tenant management)
        Route::get('/stores', [AdminStoreController::class, 'index']);
        Route::get('/stores/{id}', [AdminStoreController::class, 'show']);
        Route::patch('/stores/{id}/approve', [AdminStoreController::class, 'approve']);
        Route::patch('/stores/{id}/suspend', [AdminStoreController::class, 'suspend']);
        Route::delete('/stores/{id}', [AdminStoreController::class, 'destroy']);

        // Venues oversight
        Route::get('/venues', [AdminVenueController::class, 'index']);
        Route::get('/venues/{id}', [AdminVenueController::class, 'show']);
        Route::patch('/venues/{id}/toggle-status', [AdminVenueController::class, 'toggleStatus']);

        // Bookings oversight
        Route::get('/bookings', [AdminBookingController::class, 'index']);
        Route::get('/bookings/{id}', [AdminBookingController::class, 'show']);

        // Categories
        Route::apiResource('categories', AdminCategoryController::class);

        // Profile (name, email, password; avatar via global /profile/avatar)
        Route::get('/profile', [AdminProfileController::class, 'show']);
        Route::put('/profile', [AdminProfileController::class, 'update']);
    });

// ─── Notifications (all authenticated roles) ─────────────────────────────────
use App\Http\Controllers\Api\NotificationController;

Route::middleware('jwt')->group(function () {
    Route::get('/notifications',            [NotificationController::class, 'index']);
    Route::get('/notifications/unread',     [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read',[NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all',  [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}',    [NotificationController::class, 'destroy']);
});

// ─── Profile Management (all authenticated users) ───────────────────────────
use App\Http\Controllers\Api\ProfileController;

Route::middleware('jwt')->group(function () {
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar']);
});

// ─── Store Profile (public view) ─────────────────────────────────────────────
use App\Http\Controllers\Api\PublicStoreController;
Route::get('/stores/{id}/profile', [PublicStoreController::class, 'show']);

// ─── Venue Availability (public) ─────────────────────────────────────────────
use App\Http\Controllers\Api\VenueAvailabilityController;
Route::get('/venues/{venueId}/availability', [VenueAvailabilityController::class, 'check']);
Route::get('/venues/{venueId}/availability/month', [VenueAvailabilityController::class, 'getMonthAvailability']);

// ─── PayFast Payment Gateway (SANDBOX) ──────────────────────────────────────
// ╔══════════════════════════════════════════════════════════════════════╗
// ║  SANDBOX MODE — Final Year Project Demo Only                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
use App\Http\Controllers\Api\PayFastController;

// Protected: generate PayFast payment data for a booking (client only)
Route::prefix('client')
    ->middleware(['jwt', 'role:client'])
    ->group(function () {
        // Get PayFast form fields + signature for a booking
        Route::get('/bookings/{id}/payment-data', [PayFastController::class, 'paymentData']);
        // Manual payment confirmation (sandbox — ITN can't reach localhost)
        Route::post('/bookings/{id}/confirm-payment', [PayFastController::class, 'confirmPayment']);
    });

// Public: PayFast ITN callback (NO auth — PayFast sends this server-to-server)
Route::post('/payfast/itn', [PayFastController::class, 'itn']);

// ─── Broadcasting Auth (JWT-compatible) ──────────────────────────────────────
use App\Http\Controllers\Api\BroadcastAuthController;

Route::middleware('jwt')->post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

// ─── NexusChat System (all authenticated users) ──────────────────────────────
use App\Http\Controllers\Api\NexusChatController;
use App\Http\Controllers\Api\UserController;

Route::middleware('jwt')->group(function () {
    Route::get('/users', [UserController::class, 'index']);

    // Chat CRUD
    Route::get('/nexus/chats', [NexusChatController::class, 'index']);
    Route::post('/nexus/chats', [NexusChatController::class, 'store']);
    Route::get('/nexus/chats/{chat}', [NexusChatController::class, 'show']);
    Route::delete('/nexus/chats/{chat}', [NexusChatController::class, 'destroy']);

    // Messages
    Route::get('/nexus/chats/{chat}/messages', [NexusChatController::class, 'messages']);
    Route::post('/nexus/chats/{chat}/messages', [NexusChatController::class, 'sendMessage']);
    Route::put('/nexus/messages/{message}', [NexusChatController::class, 'editMessage']);
    Route::delete('/nexus/messages/{message}', [NexusChatController::class, 'deleteMessage']);

    // Read receipts
    Route::post('/nexus/chats/{chat}/read', [NexusChatController::class, 'markRead']);

    // Reactions
    Route::post('/nexus/messages/{message}/reactions', [NexusChatController::class, 'toggleReaction']);

    // Search users to start a chat
    Route::get('/nexus/users/search', [NexusChatController::class, 'searchUsers']);
    Route::get('/nexus/eligible-contacts', [NexusChatController::class, 'eligibleContacts']);
});
