<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;


/**
 * DatabaseSeeder
 * * This class populates the NiaLink database with initial test data.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MerchantSeeder::class,
            AdminSeeder::class,
        ]);

        $this->command->info('NiaLink Test Data Seeded Successfully!');
    }
}
