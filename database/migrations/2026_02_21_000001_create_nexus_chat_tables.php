<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NexusChat — Complete chat system schema.
 *
 * Supports: 1-on-1 chats, group chats, message status tracking,
 * reactions, replies, forwarding, editing, soft-deletes, and media.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Drop old chat tables ────────────────────────────────────────────
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');

        // ─── Chats (rooms — both 1-on-1 and group) ──────────────────────────
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['private', 'group'])->default('private');
            $table->string('name')->nullable();          // Group name (null for private)
            $table->string('avatar')->nullable();         // Group avatar path
            $table->text('description')->nullable();      // Group description
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        // ─── Chat Participants (pivot) ───────────────────────────────────────
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['member', 'admin', 'owner'])->default('member');
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
            $table->index(['user_id', 'left_at']);      // For listing active chats
        });

        // ─── Messages ────────────────────────────────────────────────────────
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();             // Text content (null for media-only)
            $table->enum('type', [
                'text', 'image', 'video', 'audio', 'file', 'voice', 'gif', 'system'
            ])->default('text');

            // Reply & Forward support
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('forwarded_from_id')->nullable()->constrained('messages')->nullOnDelete();

            // Media attachments (JSON array of {path, name, mime, size})
            $table->json('attachments')->nullable();

            // Edit & Delete tracking
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();

            $table->timestamps();

            $table->index(['chat_id', 'created_at']);
            $table->index(['sender_id', 'created_at']);
        });

        // ─── Message Reads (delivery + read status per user) ─────────────────
        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        // ─── Message Reactions ───────────────────────────────────────────────
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        // ─── Add last_seen_at to users (for online presence) ─────────────────
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chats');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
