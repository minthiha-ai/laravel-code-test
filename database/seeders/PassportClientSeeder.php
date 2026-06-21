<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Passport;

class PassportClientSeeder extends Seeder
{
    // Recreate the password grant client with the fixed .env credentials so
    // they survive migrate:fresh (which wipes oauth_clients).
    public function run(): void
    {
        $id = config('passport.password_client_id');
        $secret = config('passport.password_client_secret');

        if (empty($id) || empty($secret)) {
            $this->command->warn(
                'PASSPORT_PASSWORD_CLIENT_ID / _SECRET are not set; skipping password client seed. '
                . 'Run `php artisan passport:client --password` and add the values to your .env.'
            );

            return;
        }

        Passport::client()->newQuery()->updateOrCreate(
            ['id' => $id],
            [
                'name' => 'employee-api password grant',
                'secret' => $secret,
                'provider' => 'users',
                'redirect_uris' => [],
                'grant_types' => ['password', 'refresh_token'],
                'revoked' => false,
            ],
        );

        $this->command->info('Password grant client seeded.');
    }
}
