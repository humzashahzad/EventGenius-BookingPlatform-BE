<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    /**
     * Create a notification for one or more users.
     */
    public static function send(int|array $userIds, string $type, string $title, string $body, array $data = []): void
    {
        $ids = is_array($userIds) ? $userIds : [$userIds];

        $rows = array_map(fn($id) => [
            'user_id'    => $id,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'data'       => json_encode($data),
            'is_read'    => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids);

        Notification::insert($rows);
    }

    /**
     * Notify all admin users.
     */
    public static function notifyAdmins(string $type, string $title, string $body, array $data = []): void
    {
        $adminIds = User::where('role', 'admin')->where('is_active', true)->pluck('id')->toArray();
        if ($adminIds) {
            self::send($adminIds, $type, $title, $body, $data);
        }
    }

    /**
     * Notify the owner of a store.
     */
    public static function notifyStoreOwner(int $storeOwnerId, string $type, string $title, string $body, array $data = []): void
    {
        self::send($storeOwnerId, $type, $title, $body, $data);
    }

    /**
     * Notify a client user.
     */
    public static function notifyClient(int $clientId, string $type, string $title, string $body, array $data = []): void
    {
        self::send($clientId, $type, $title, $body, $data);
    }
}
