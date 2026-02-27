<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'parent_id', 'name', 'level', 'boundary', 'is_active',
    ];

    protected $casts = [
        'boundary' => 'array',
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function scopeCountries($query)
    {
        return $query->where('level', 'country');
    }

    public function scopeCities($query)
    {
        return $query->where('level', 'city');
    }

    public function scopeAreas($query)
    {
        return $query->where('level', 'area');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
