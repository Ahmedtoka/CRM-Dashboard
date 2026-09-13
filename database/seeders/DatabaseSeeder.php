<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the 30-day social CRM demo dataset (spec §10): users, channels, catalog,
     * bot rules, and a realistic replay of conversations/comments/orders/shipments.
     *
     * Only in local/testing: the demo users share the password "password" and the
     * channel accounts are fake, so a production `db:seed` must never create them.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Skipping DemoSeeder: demo data is only seeded in local/testing environments.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
