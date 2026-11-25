<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalonCoverImage extends Model
{
    protected $fillable = [
        'salon_id',
        'image_path',
        'sort_order',
    ];

    public function salon()
    {
        return $this->belongsTo(Salon::class);
    }
}