<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-owner-user')]
#[Description('Crea el usuario propietario inicial de la aplicación.')]
class CreateOwnerUser extends Command
{
    /**
     * The minimum number of characters required for the password.
     */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = text(
            label: 'Nombre',
            required: 'El nombre es obligatorio.',
        );

        $email = text(
            label: 'Correo electrónico',
            required: 'El correo electrónico es obligatorio.',
            validate: fn (string $value): ?string => match (true) {
                ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'El correo electrónico no es válido.',
                User::query()->where('email', $value)->exists() => 'Ya existe un usuario con este correo electrónico.',
                default => null,
            },
        );

        $password = password(
            label: 'Contraseña',
            required: 'La contraseña es obligatoria.',
            validate: fn (string $value): ?string => mb_strlen($value) < self::MIN_PASSWORD_LENGTH
                ? sprintf('La contraseña debe tener al menos %d caracteres.', self::MIN_PASSWORD_LENGTH)
                : null,
        );

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->components->info("Usuario propietario «{$user->name}» creado correctamente.");

        return self::SUCCESS;
    }
}
