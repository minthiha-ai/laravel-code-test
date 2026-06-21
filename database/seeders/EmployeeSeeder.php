<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeSeeder extends Seeder
{
    /**
     * Number of employees to generate.
     */
    private const TOTAL = 10000;

    /**
     * Rows inserted per query. Keeps memory flat and avoids 10k single inserts.
     */
    private const CHUNK = 1000;

    /**
     * Seed 10,000 employees using batched raw inserts.
     *
     * Faker generates the data, but rows are pushed with
     * DB::table()->insert() in chunks rather than per-row Eloquent saves.
     * Because raw inserts bypass Eloquent, created_at/updated_at must be set
     * explicitly or the non-nullable timestamp columns would fail.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create();
        // Fixed seed => reproducible data across reseeds, so the bundled sample
        // import file (storage/samples/employees_sample.xlsx) always references
        // emails that actually exist.
        $faker->seed(20260620);
        $now = now();
        $rows = [];

        for ($i = 1; $i <= self::TOTAL; $i++) {
            $firstName = $faker->firstName();
            $lastName = $faker->lastName();

            $rows[] = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                // Append the row index to guarantee uniqueness across 10k rows
                // without exhausting Faker's unique() pool.
                'email' => Str::lower("{$firstName}.{$lastName}.{$i}@example.com"),
                'phone' => $faker->numerify('+1-###-###-####'),
                'address' => str_replace("\n", ', ', $faker->address()),
                'salary' => $faker->numberBetween(30000, 200000),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === self::CHUNK) {
                DB::table('employees')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('employees')->insert($rows);
        }

        $this->command->info(self::TOTAL . ' employees seeded.');
    }
}
