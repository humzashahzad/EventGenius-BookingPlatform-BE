<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id', 'name', 'slug', 'description', 'thumbnail',
        'address', 'city', 'state', 'country', 'latitude', 'longitude',
        'capacity_min', 'capacity_max', 'area_sqft', 'floors',
        'price_per_hour', 'price_per_day', 'price_per_event', 'price_per_head',
        'pricing_type', 'dynamic_pricing', 'event_types',
        'operating_start', 'operating_end',
        'status', 'is_featured', 'avg_rating', 'total_reviews', 'total_bookings',
    ];

    protected $casts = [
        'event_types' => 'array',
        'dynamic_pricing' => 'boolean',
        'is_featured' => 'boolean',
        'price_per_hour' => 'float',
        'price_per_day' => 'float',
        'price_per_event' => 'float',
        'price_per_head' => 'float',
        'avg_rating' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function images()
    {
        return $this->hasMany(VenueImage::class)->orderBy('sort_order');
    }

    public function amenities()
    {
        return $this->hasMany(VenueAmenity::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function getPriceAttribute()
    {
        return $this->price_per_head ?? $this->price_per_hour ?? $this->price_per_day ?? $this->price_per_event;
    }
}
