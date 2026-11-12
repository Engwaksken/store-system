<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Base reference data always; demo data only outside production. */
    public function run(): void
    {
        $this->call(BaseSeeder::class);
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
