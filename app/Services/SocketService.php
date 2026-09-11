<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocketService
{
    private string $socketUrl;

    public function __construct()
    {
        $this->socketUrl = env('SOCKET_IO_URL', 'http://localhost:3000');
    }

    /**
     * Broadcast message to Socket.io server via Redis
     */
    public function broadcastMessage(array $data): void
    {
        try {
            if (extension_loaded('redis')) {
                app('redis')->connection()->publish('chat:message', json_encode($data));
            } else {
                Log::warning('[SocketService] Redis not available, using HTTP fallback');
                $this->broadcastViaHttp($data);
            }
        } catch (\Exception $e) {
            Log::error('[SocketService] Failed to broadcast message', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * Broadcast via direct HTTP (fallback method)
     */
    private function broadcastViaHttp(array $data): void
    {
        try {
            Http::timeout(2)->post("{$this->socketUrl}/broadcast", $data);
        } catch (\Exception $e) {
            Log::error('[SocketService] HTTP broadcast failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify user online status (broadcast to all via chat:events)
     */
    public function notifyUserOnline(int $userId): void
    {
        $this->publish('chat:events', [
            'type' => 'user:online',
            'userId' => $userId,
        ]);
    }

    /**
     * Notify user offline status
     */
    public function notifyUserOffline(int $userId): void
    {
        $this->publish('chat:events', [
            'type' => 'user:offline',
            'userId' => $userId,
        ]);
    }

    /**
     * Broadcast read receipt so sender sees double checkmarks
     */
    public function broadcastReadReceipt(array $data): void
    {
        $this->publish('chat:read_receipt', $data);
    }

    /**
     * Broadcast reaction update to other participant
     */
    public function broadcastReaction(array $data): void
    {
        $this->publish('chat:reaction', $data);
    }

    private function publish(string $channel, array $data): void
    {
        try {
            if (extension_loaded('redis')) {
                app('redis')->connection()->publish($channel, json_encode($data));
            }
        } catch (\Exception $e) {
            Log::error("[SocketService] Publish failed [{$channel}]", [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }
}
