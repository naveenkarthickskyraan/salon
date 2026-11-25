<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Salon extends Model
{
    protected $fillable = [
        'partner_id', 'business_name', 'business_type', 'email', 'email_verified_at',
        'owner_name', 'mobile_number', 'mobile_verified_at', 'alternative_number',
        'alternative_number_verified_at', 'pincode', 'country', 'state', 'city',
        'locality', 'address_line', 'landmark', 'latitude', 'longitude', 'status',
    ];

    protected $casts = [
        'latitude'                       => 'float',
        'longitude'                      => 'float',
        'email_verified_at'              => 'datetime',
        'mobile_verified_at'             => 'datetime',
        'alternative_number_verified_at' => 'datetime',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function coverImages()
    {
        return $this->hasMany(SalonCoverImage::class)->orderBy('sort_order');
    }

    public function workingHours()
    {
        return $this->hasMany(SalonWorkingHour::class);
    }
    public function categories(): HasMany
    {
        return $this->hasMany(SalonCategory::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(SalonService::class);
    }
}