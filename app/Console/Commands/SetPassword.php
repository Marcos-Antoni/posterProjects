<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * The only way to change a password in Marcos OS: the web UI deliberately
 * has no password or profile form. Both entries are read with hidden
 * input, validated with the application's default password rules, and
 * never echoed, logged or passed on the command line.
 */
#[Signature('marcos:set-password {email : Correo del usuario}')]
#[Description('Cambia la contraseña de un usuario (pide la nueva contraseña dos veces, sin mostrarla).')]
class SetPassword extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        $password = password(label: 'Nueva contraseña', required: 'La contraseña es obligatoria.');
        $confirmation = password(label: 'Repetí la nueva contraseña', required: 'La contraseña es obligatoria.');

        if (! hash_equals($password, $confirmation)) {
            $this->components->error('Las contraseñas no coinciden. No se cambió nada.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]],
            [
                'password.min' => 'La contraseña debe tener al menos :min caracteres.',
                'password.password.mixed' => 'La contraseña debe combinar mayúsculas y minúsculas.',
                'password.password.letters' => 'La contraseña debe tener al menos una letra.',
                'password.password.numbers' => 'La contraseña debe tener al menos un número.',
                'password.password.symbols' => 'La contraseña debe tener al menos un símbolo.',
                'password.password.uncompromised' => 'Esa contraseña apareció en una filtración de datos. Elegí otra.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            $this->components->warn('No se cambió nada.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        $this->invalidateSessions($user);

        $this->components->info("Contraseña actualizada para {$user->email}.");

        return self::SUCCESS;
    }

    /**
     * End every web session of the user (the CLI equivalent of logging out
     * other devices): with the database session driver their rows are
     * deleted; the rotated remember token already kills "remember me"
     * cookies. Other drivers cannot be enumerated, so the owner is told.
     */
    private function invalidateSessions(User $user): void
    {
        if (Config::string('session.driver') !== 'database') {
            $this->components->warn('Las sesiones abiertas no se pueden cerrar con el driver de sesión actual; cerrá sesión en tus navegadores.');

            return;
        }

        $closed = DB::connection(Config::get('session.connection'))
            ->table(Config::string('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();

        $this->components->info("Sesiones web cerradas: {$closed}.");
    }
}
