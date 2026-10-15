<?php

namespace Database\Seeders;

use App\Models\Publisher;
use Illuminate\Database\Seeder;

/**
 * Ensures the launch publisher exists (task 6.1). The publishers migration already
 * creates it; this keeps a re-seeded or hand-cleaned database consistent. Safe to re-run.
 */
class PublisherSeeder extends Seeder
{
    public function run(): void
    {
        Publisher::firstOrCreate(
            ['slug' => 'mthombothi-studios'],
            ['name' => 'Mthombothi Studios', 'settings' => []],
        );
    }
}
