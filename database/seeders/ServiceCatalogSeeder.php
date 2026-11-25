<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\ServiceName;
use Illuminate\Database\Seeder;

/**
 * Master dropdown lists. They are the same for every salon and are not mapped to each other.
 * "Others" is added by the app, it is not stored here.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $list = [
            'Senior Stylist', 'Hair Stylist', 'Beautician', 'Makeup Artist',
            'Nail Technician', 'Skin Therapist', 'Massage Therapist', 'Spa Therapist',
        ];

        foreach ($list as $i => $name) {
            ServiceCategory::updateOrCreate(['name' => $name], ['sort_order' => $i + 1, 'is_active' => true]);
            ServiceName::updateOrCreate(['name' => $name], ['sort_order' => $i + 1, 'is_active' => true]);
        }
    }
}
