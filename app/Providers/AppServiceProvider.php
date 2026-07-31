<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure the named rate limiters for the `/api/v1` bearer surface.
     *
     * Two composite limits apply to every login attempt (successful or
     * not): 5/min keyed by `email|ip`, and 10/min keyed by `ip` alone —
     * strictly stricter than the web login, which has only the first.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api-login', fn (Request $request): array => [
            Limit::perMinute(5)
                ->by(Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip()))
                ->response($this->throttled(...)),
            Limit::perMinute(10)
                ->by($request->ip())
                ->response($this->throttled(...)),
        ]);
    }

    /**
     * Build the Spanish 429 response for a throttled `/api/v1/login` request.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function throttled(Request $request, array $headers): JsonResponse
    {
        return response()->json([
            'message' => sprintf(
                'Demasiados intentos de acceso. Por favor, inténtalo de nuevo en %d segundos.',
                $headers['Retry-After']
            ),
        ], 429, $headers);
    }
}
