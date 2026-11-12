<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MockSeeder::class);
        $this->call(CustomerGeoSeeder::class);
        $this->call(SupplierCatalogSeeder::class);
        $this->call(DemandHistorySeeder::class);
        $this->call(TrackingHistorySeeder::class);
        $this->call(DemoStorySeeder::class);
    }
}
