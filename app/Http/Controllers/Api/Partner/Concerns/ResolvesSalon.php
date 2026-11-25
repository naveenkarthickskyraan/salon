<?php

namespace App\Http\Controllers\Api\Partner\Concerns;

use App\Models\Salon;
use Illuminate\Http\Request;

trait ResolvesSalon
{
    /**
     * The salon must belong to the logged-in partner. Anything else is a 404,
     * so one partner can never read or change another partner's salon.
     */
    protected function ownedSalon(Request $request, int $salonId): Salon
    {
        return Salon::where('partner_id', $request->user()->id)->findOrFail($salonId);
    }
}
