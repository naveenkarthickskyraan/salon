<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalonCategory extends Model
{
    protected $fillable = ['salon_id', 'service_category_id', 'name'];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(SalonService::class, 'salon_category_id');
    }
}