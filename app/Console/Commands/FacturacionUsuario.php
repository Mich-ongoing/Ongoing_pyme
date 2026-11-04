<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Crea (o reinicia la contraseña de) un usuario que solo ve la facturación. */
class FacturacionUsuario extends Command
{
    protected $signature = 'facturacion:usuario {email} {--nombre=Facturación}';

    protected $description = 'Crea un usuario con rol «facturacion» y muestra su contraseña';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $existente = User::where('email', $email)->first();
        if ($existente && $existente->role !== 'facturacion') {
            $this->error("{$email} ya existe con rol «{$existente->role}»: no lo convierto en usuario de facturación.");

            return self::FAILURE;
        }
        $clave = Str::password(16, symbols: false);
        User::updateOrCreate(['email' => $email], ['name' => $this->option('nombre'), 'password' => $clave, 'role' => 'facturacion']);
        $this->info(($existente ? 'Contraseña nueva' : 'Usuario creado') . " para {$email}: {$clave}");

        return self::SUCCESS;
    }
}
