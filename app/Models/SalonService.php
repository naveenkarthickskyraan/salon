<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SalonService extends Model
{
    public const FOR_WHOM = [
        'men'      => 'Men',
        'women'    => 'Women',
        'kids'     => 'Kids',
        'everyone' => 'Everyone',
    ];

    public const DURATIONS = [15, 30, 45, 60, 75, 90, 105, 120, 150, 180];

    protected $fillable = [
        'salon_id', 'salon_category_id', 'service_name_id', 'name',
        'duration_minutes', 'for_whom', 'price',
        'cover_image', 'is_active',
    ];

    protected $casts = [
        'price'            => 'decimal:2',
        'duration_minutes' => 'integer',
        'is_active'        => 'boolean',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SalonCategory::class, 'salon_category_id');
    }

    public static function durationLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return "$minutes Min";
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest ? "{$hours} hr {$rest} min" : "{$hours} hr";
    }

    public function coverUrl(): ?string
    {
        return $this->cover_image
            ? Storage::disk('public')->url($this->cover_image)
            : null;
    }

    /** Placeholder until the bookings table exists: true when a future, non-cancelled booking uses this service. */
    public function hasUpcomingBookings(): bool
    {
        return false;
    }

    public function toApiArray(): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'category'         => ['id' => $this->salon_category_id, 'name' => $this->category?->name],
            'for_whom'         => $this->for_whom,
            'for_whom_label'   => self::FOR_WHOM[$this->for_whom] ?? $this->for_whom,
            'duration_minutes' => $this->duration_minutes,
            'duration_label'   => self::durationLabel($this->duration_minutes),
            'price'            => (float) $this->price,
            'cover_image_url'  => $this->coverUrl(),
            'is_active'        => $this->is_active,
            'status'           => $this->is_active ? 'Active' : 'Hidden',
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}