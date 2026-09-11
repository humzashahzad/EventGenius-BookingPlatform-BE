<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Throwable;

class NotificationService
{
    /**
     * Create a notification for one or more users.
     */
    public static function send(int|array $userIds, string $type, string $title, string $body, array $data = []): void
    {
        $ids = is_array($userIds) ? $userIds : [$userIds];

        foreach ($ids as $id) {
            $notification = Notification::create([
                'user_id' => $id,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'data'    => $data,
                'is_read' => false,
            ]);

            try {
                event(new NotificationCreated($notification));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
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
