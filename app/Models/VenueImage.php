<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VenueImage extends Model
{
    use SoftDeletes;
    protected $fillable = ['venue_id', 'path', 'alt_text', 'is_primary', 'sort_order'];

    protected $appends = ['url'];

    protected $casts = ['is_primary' => 'boolean'];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function getUrlAttribute()
    {
        return asset('storage/' . $this->path);
    }
}
