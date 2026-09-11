<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VenueAmenity extends Model
{
    use SoftDeletes;
    protected $fillable = ['venue_id', 'name', 'icon', 'is_paid', 'price'];

    protected $casts = [
        'is_paid' => 'boolean',
        'price' => 'float',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
