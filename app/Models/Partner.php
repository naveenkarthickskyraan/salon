<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Partner extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'email', 'password', 'otp', 'otp_expires_at', 'email_verified_at',
        'google_id', 'apple_id', 'device_id', 'device_token', 'device_type', 'app_version',
    ];
    protected $hidden = ['password', 'otp'];
    protected $casts = [
        'password' => 'hashed',
        'otp_expires_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function salon() { 
        return $this->hasOne(\App\Models\Salon::class); 
    }
}