<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Passport;

class PassportClientSeeder extends Seeder
{
    /**
     * Seed the password grant client used by the login mutation.
     *
     * `migrate:fresh` wipes oauth_clients, so the client is recreated here with
     * the fixed credentials from config/.env. This keeps PASSPORT_PASSWORD_CLIENT_*
     * stable across reseeds instead of changing every time.
     */
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
