<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalonWorkingHour extends Model
{
    protected $fillable = [
        'salon_id',
        'day',
        'is_open',
        'opening_time',
        'closing_time',
    ];

    protected $casts = [
        'is_open' => 'boolean',
    ];

    public function salon()
    {
        return $this->belongsTo(Salon::class);
    }
}