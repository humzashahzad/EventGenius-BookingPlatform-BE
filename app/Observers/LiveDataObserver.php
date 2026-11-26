<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Store;
use App\Models\StoreLandingPage;
use App\Models\UserSession;
use App\Models\Venue;
use App\Models\VenueAmenity;
use App\Models\VenueImage;
use App\Services\LiveData;
use Illuminate\Database\Eloquent\Model;

/**
 * Reports Eloquent changes of the shared app records to LiveData (see docs/live-data.md).
 * Related ids come straight from the model's own foreign keys: no queries here.
 */
class LiveDataObserver
{
    public function created(Model $model): void
    {
        LiveData::changed($model, (int) $model->getKey(), 'created', $this->related($model));
    }

    public function updated(Model $model): void
    {
        // Restores also arrive here (restore() saves deleted_at = null).
        LiveData::changed($model, (int) $model->getKey(), 'updated', $this->related($model), array_keys($model->getChanges()));
    }

    public function deleted(Model $model): void
    {
        LiveData::changed($model, (int) $model->getKey(), 'deleted', $this->related($model));
    }

    public function forceDeleted(Model $model): void
    {
        $this->deleted($model);
    }

    public static function related(Model $model): array
    {
        $a = fn (string $key) => $model->getAttribute($key);

        return match (true) {
            $model instanceof Booking => ['venue_id' => $a('venue_id'), 'store_id' => $a('store_id'), 'client_id' => $a('client_id')],
            $model instanceof Payment => ['booking_id' => $a('booking_id'), 'client_id' => $a('client_id')],
            $model instanceof Review => ['venue_id' => $a('venue_id'), 'booking_id' => $a('booking_id'), 'client_id' => $a('client_id')],
            $model instanceof Venue => ['store_id' => $a('store_id')],
            $model instanceof VenueImage, $model instanceof VenueAmenity => ['venue_id' => $a('venue_id')],
            $model instanceof Store => ['owner_id' => $a('user_id')],
            $model instanceof StoreLandingPage => ['store_id' => $a('store_id')],
            $model instanceof UserSession => ['user_id' => $a('user_id')],
            default => [],
        };
    }
}
