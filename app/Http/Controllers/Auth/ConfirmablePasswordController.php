<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The password confirmation screen (mockup 30) behind Laravel's
 * `password.confirm` middleware. A confirmation is valid for
 * `auth.password_timeout` seconds (15 minutes); the AI bridge and AI
 * permission grants will require it.
 */
class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password page.
     */
    public function show(): Response
    {
        return Inertia::render('auth/confirm-password');
    }

    /**
     * Confirm the user's password and continue to the intended action.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['password' => ['required', 'string']],
            ['password.required' => 'Escribí tu contraseña para seguir.'],
        );

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->string('password')->toString(),
        ])) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no coincide. Probá de nuevo.',
            ]);
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('home', absolute: false));
    }
}
