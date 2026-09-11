<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreLandingPage extends Model
{
    protected $fillable = [
        'store_id', 'hero_image', 'tagline', 'about_text',
        'services', 'faq', 'social_links', 'testimonials', 'gallery',
    ];

    protected $casts = [
        'services'     => 'array',
        'faq'          => 'array',
        'social_links' => 'array',
        'testimonials' => 'array',
        'gallery'      => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
