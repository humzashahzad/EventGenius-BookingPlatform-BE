<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'venue_id', 'booking_id', 'client_id', 'rating', 'title', 'comment',
        'cleanliness_rating', 'value_rating', 'service_rating', 'location_rating',
        'is_approved', 'store_reply', 'store_replied_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_approved' => 'boolean',
        'store_replied_at' => 'datetime',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
