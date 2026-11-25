<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_number', 'venue_id', 'client_id', 'store_id',
        'event_name', 'event_type', 'special_requirements', 'expected_guests',
        'event_date', 'start_time', 'end_time', 'duration_hours',
        'base_price', 'amenities_price', 'discount_amount', 'tax_amount', 'total_amount',
        'status', 'payment_status', 'paid_at', 'cancellation_reason', 'rejection_reason', 'confirmed_at', 'cancelled_at',
    ];

    protected $casts = [
        'event_date' => 'date',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'paid_at' => 'datetime',
        'base_price' => 'float',
        'amenities_price' => 'float',
        'discount_amount' => 'float',
        'tax_amount' => 'float',
        'total_amount' => 'float',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function payment()
    {
        // Latest payment attempt (a booking may have cancelled/failed attempts)
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = 'BK-' . strtoupper(uniqid());
            }
        });
    }
}
