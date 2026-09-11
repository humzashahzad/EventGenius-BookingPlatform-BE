<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Concerns\HasInternalKeys;
use Stancl\Tenancy\Database\Concerns\TenantRun;

class Store extends Model implements TenantWithDatabase
{
    use HasFactory, SoftDeletes;
    use CentralConnection, HasDatabase, HasDomains, HasInternalKeys, TenantRun;

    protected $fillable = [
        'user_id', 'name', 'slug', 'description', 'logo', 'cover_image',
        'address', 'city', 'state', 'country', 'latitude', 'longitude',
        'phone', 'email', 'website', 'status', 'tenancy_db_name',
        'business_hours', 'social_links',
    ];

    protected $casts = [
        'business_hours' => 'array',
        'social_links'   => 'array',
        'latitude'       => 'float',
        'longitude'      => 'float',
    ];

    // ── stancl/tenancy: slug is the tenant key ─────────────────────────────────

    public function getTenantKeyName(): string
    {
        return 'slug';
    }

    public function getTenantKey(): string
    {
        return $this->slug;
    }

    // The columns that are NOT stored in the JSON data column (we have real columns)
    public static function getCustomColumns(): array
    {
        return [
            'id', 'user_id', 'name', 'slug', 'description', 'logo', 'cover_image',
            'address', 'city', 'state', 'country', 'latitude', 'longitude',
            'phone', 'email', 'website', 'status', 'tenancy_db_name',
            'business_hours', 'social_links', 'created_at', 'updated_at', 'deleted_at',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function venues()
    {
        return $this->hasMany(Venue::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function landingPage()
    {
        return $this->hasOne(StoreLandingPage::class);
    }
}
