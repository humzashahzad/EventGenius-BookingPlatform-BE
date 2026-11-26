<?php

namespace Tests\Unit;

use App\Events\EntityChanged;
use App\Services\LiveData;
use PHPUnit\Framework\TestCase;

class LiveDataTest extends TestCase
{
    /** Fake lookups: store 42 is owned by user 7; venue 90 belongs to store 42; booking 123 is client 404's at venue 90. */
    private function live(array &$sent, array &$lookups = []): LiveData
    {
        return new LiveData(
            function (EntityChanged $e) use (&$sent) {
                $sent[] = $e;
            },
            function (string $kind, int $id) use (&$lookups) {
                $lookups[] = "{$kind}:{$id}";
                return match ("{$kind}:{$id}") {
                    'store:42'        => 7,
                    'venue:90'        => ['store_id' => 42, 'user_id' => 7],
                    'booking:123'     => ['venue_id' => 90, 'store_id' => 42, 'client_id' => 404, 'user_id' => 7],
                    'store_venues:42' => [90, 91],
                    default           => null,
                };
            },
        );
    }

    /** @return array<string, array{channels: string[], payload: array}> keyed by audience */
    private function byAudience(array $sent): array
    {
        $out = [];
        foreach ($sent as $e) {
            $out[$e->audience] = [
                'channels' => array_map(fn ($c) => $c->name, $e->broadcastOn()),
                'payload'  => $e->broadcastWith(),
            ];
        }
        return $out;
    }

    public function test_booking_goes_to_client_owner_admins_and_venue_without_client_id_in_public(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('booking', 123, 'updated', ['venue_id' => 90, 'store_id' => 42, 'client_id' => 404], ['status', 'updated_at']);
        $this->assertSame(2, $live->flush());

        $a = $this->byAudience($sent);
        $this->assertSame(['private-user.404', 'private-user.7', 'private-admins'], $a['private']['channels']);
        $this->assertSame(['venue.90'], $a['public']['channels']);
        $this->assertSame(404, $a['private']['payload']['client_id']);
        $this->assertArrayNotHasKey('client_id', $a['public']['payload']);
        $this->assertSame(['status'], $a['public']['payload']['fields']);
        $this->assertSame(123, $a['public']['payload']['booking_id']);
        $this->assertSame('entity.changed', $sent[0]->broadcastAs());
    }

    public function test_coalesces_same_change_and_merges_fields(): void
    {
        $sent = [];
        $lookups = [];
        $live = $this->live($sent, $lookups);
        $live->record('booking', 123, 'updated', ['venue_id' => 90, 'store_id' => 42, 'client_id' => 404], ['status']);
        $live->record('booking', 123, 'updated', ['venue_id' => 90, 'store_id' => 42, 'client_id' => 404], ['payment_status', 'status']);
        $this->assertCount(1, $live->pending());
        $live->flush();

        $this->assertCount(2, $sent); // one private + one public
        $this->assertSame(['status', 'payment_status'], $sent[0]->payload['fields']);
        $this->assertSame(['store:42'], $lookups); // a single owner lookup
        $this->assertSame([], $live->pending());
    }

    public function test_update_after_create_in_same_request_is_dropped(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('venue', 90, 'created', ['store_id' => 42]);
        $live->record('venue', 90, 'updated', ['store_id' => 42], ['thumbnail']);
        $this->assertCount(1, $live->pending());
        $this->assertSame('created', $live->pending()[0]['action']);
    }

    public function test_bookkeeping_only_updates_are_ignored(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('session', 5, 'updated', ['user_id' => 3], ['last_used_at', 'updated_at']);
        $live->record('user', 3, 'updated', [], ['last_seen_at']);
        $live->record('user', 4, 'updated', [], ['password', 'updated_at']);
        $this->assertSame([], $live->pending());
    }

    public function test_user_and_session_are_private_only(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('user', 3, 'updated', [], ['is_active']);
        $live->record('session', 5, 'updated', ['user_id' => 3], ['is_revoked']);
        $live->flush();

        $this->assertCount(2, $sent);
        foreach ($sent as $e) {
            $this->assertSame(EntityChanged::AUDIENCE_PRIVATE, $e->audience);
            $this->assertSame(['private-user.3', 'private-admins'], array_map(fn ($c) => $c->name, $e->broadcastOn()));
            $this->assertArrayNotHasKey('user_id', $e->broadcastWith()); // routing ids are never sent
        }
    }

    public function test_venue_catalog_only_for_listing_fields(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('venue', 90, 'updated', ['store_id' => 42], ['description']);
        $live->flush();
        $a = $this->byAudience($sent);
        $this->assertSame(['private-user.7', 'private-admins'], $a['private']['channels']);
        $this->assertSame(['venue.90'], $a['public']['channels']);

        $sent = [];
        $live->record('venue', 90, 'updated', [], ['avg_rating', 'total_reviews']); // rating refresh: store found via lookup
        $live->flush();
        $a = $this->byAudience($sent);
        $this->assertSame(['private-user.7', 'private-admins'], $a['private']['channels']);
        $this->assertSame(['venue.90', 'catalog'], $a['public']['channels']);
        $this->assertSame(42, $a['public']['payload']['store_id']);
    }

    public function test_review_reaches_reviewer_owner_and_venue_page(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('review', 11, 'updated', ['venue_id' => 90, 'booking_id' => 123, 'client_id' => 404], ['store_reply', 'store_replied_at']);
        $live->flush();
        $a = $this->byAudience($sent);
        $this->assertSame(['private-user.404', 'private-user.7', 'private-admins'], $a['private']['channels']);
        $this->assertSame(['venue.90'], $a['public']['channels']);
        $this->assertArrayNotHasKey('client_id', $a['public']['payload']);
    }

    public function test_payment_resolves_booking_and_is_private(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('payment', 55, 'updated', ['booking_id' => 123, 'client_id' => 404], ['status']);
        $live->flush();
        $this->assertCount(1, $sent);
        $this->assertSame(['private-user.404', 'private-user.7', 'private-admins'], array_map(fn ($c) => $c->name, $sent[0]->broadcastOn()));
        $this->assertSame(90, $sent[0]->payload['venue_id']);
        $this->assertSame(42, $sent[0]->payload['store_id']);
    }

    public function test_store_status_change_notifies_its_venue_pages_and_catalog(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('store', 42, 'updated', ['owner_id' => 7], ['status']);
        $live->flush();
        $a = $this->byAudience($sent);
        $this->assertSame(['private-user.7', 'private-admins'], $a['private']['channels']);
        $this->assertSame(['venue.90', 'venue.91', 'catalog'], $a['public']['channels']);

        $sent = [];
        $live->record('store', 42, 'updated', ['owner_id' => 7], ['phone']);
        $live->flush();
        $this->assertSame(['catalog'], $this->byAudience($sent)['public']['channels']);
    }

    public function test_category_image_amenity_and_landing_page(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('category', 2, 'updated', [], ['is_active']);
        $live->record('venue_image', 8, 'deleted', ['venue_id' => 90]);
        $live->record('venue_amenity', 9, 'created', ['venue_id' => 90]);
        $live->record('landing_page', 3, 'updated', ['store_id' => 42], ['tagline']);
        $live->flush();

        $map = [];
        foreach ($sent as $e) {
            $map[$e->payload['entity'] . ':' . $e->audience] = array_map(fn ($c) => $c->name, $e->broadcastOn());
        }
        $this->assertSame(['private-admins'], $map['category:private']);
        $this->assertSame(['catalog'], $map['category:public']);
        $this->assertSame(['private-user.7', 'private-admins'], $map['venue_image:private']);
        $this->assertSame(['venue.90'], $map['venue_image:public']);
        $this->assertSame(['venue.90'], $map['venue_amenity:public']);
        $this->assertSame(['private-user.7', 'private-admins'], $map['landing_page:private']);
        $this->assertSame(['catalog'], $map['landing_page:public']);
    }

    public function test_fields_are_capped_and_invalid_input_ignored(): void
    {
        $sent = [];
        $live = $this->live($sent);
        $live->record('venue', 90, 'updated', ['store_id' => 42], array_map(fn ($i) => "f{$i}", range(1, 15)));
        $live->record('venue', 0, 'updated');
        $live->record('venue', 91, 'touched');
        $this->assertCount(1, $live->pending());
        $this->assertCount(LiveData::MAX_FIELDS, $live->pending()[0]['fields']);
    }

    public function test_a_failing_entry_does_not_stop_the_others(): void
    {
        $sent = [];
        $calls = 0;
        $live = new LiveData(
            function (EntityChanged $e) use (&$sent, &$calls) {
                if ($calls++ === 0) {
                    throw new \RuntimeException('broadcaster down');
                }
                $sent[] = $e;
            },
            fn () => null,
        );
        $live->record('user', 1, 'updated', [], ['is_active']);
        $live->record('user', 2, 'updated', [], ['is_active']);
        $this->assertSame(1, $live->flush());
        $this->assertCount(1, $sent);
        $this->assertSame(2, $sent[0]->payload['id']);
        $this->assertSame([], $live->pending());
    }
}
