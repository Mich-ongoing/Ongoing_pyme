<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // El repo es público: nunca una contraseña fija. ADMIN_PASSWORD en .env o una aleatoria.
        $password = env('ADMIN_PASSWORD') ?: \Illuminate\Support\Str::password(20);

        User::updateOrCreate(
            ['email' => 'michell@ongoing.es'],
            [
                'name' => 'Super Admin',
                'password' => \Illuminate\Support\Facades\Hash::make($password),
                'role' => 'superadmin',
                'email_verified_at' => now(),
            ]
        );

        if (!env('ADMIN_PASSWORD')) {
            $this->command?->warn("Contraseña de michell@ongoing.es: {$password}");
        }
    }
}
