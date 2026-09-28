<?php

namespace App\Providers;

use App\Actions\Support\AuditWriter;
use App\Actions\Support\LogAuditWriter;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        // Phase 2 skeleton (design D3): AI-applied changes are recorded in the
        // log until Phase 8 adds `ai_audit_entries` and binds its writer here.
        $this->app->bind(AuditWriter::class, LogAuditWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureMorphMap();
        $this->configureRouteBindings();
    }

    /**
     * `{objective}` is a KEY resolved among the authenticated owner's visible
     * objectives (active and closed), on the web and on `/api/v1` alike.
     * Unknown, foreign, draft and retired keys are one indistinguishable 404
     * (projects and api-projects specs: never 403).
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('objective', function (string $value): Objective {
            $owner = request()->user();

            $objective = $owner instanceof User ? Objective::visibleForOwner($owner, $value) : null;

            if ($objective === null) {
                throw (new ModelNotFoundException)->setModel(Objective::class, [$value]);
            }

            return $objective;
        });
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
     * Short, stable aliases for the polymorphic Marcos OS columns
     * (`control_plans`, `control_map_entries`, `retirements`). Not enforced:
     * Sanctum's `tokenable_type` keeps its existing class-name values.
     */
    protected function configureMorphMap(): void
    {
        Relation::morphMap([
            'objective' => Objective::class,
            'plan' => Plan::class,
            'item' => Item::class,
        ]);
    }

    /**
     * Configure the named rate limiters for the `/api/v1` bearer surface.
     *
     * Two composite limits apply to every login attempt (successful or
     * not): 5/min keyed by `email|ip`, and 10/min keyed by `ip` alone —
     * strictly stricter than the web login, which has only the first.
     *
     * `api-qr-redeem` and `qr-login-mint` support the QR login pass flow
     * (design.md "Rate limiting"). `api-qr-redeem` is keyed on IP alone —
     * the only identity that exists before a pass is validated — and
     * deliberately not on the presented token, which would hand an
     * attacker a fresh bucket per guess. `qr-login-mint` is keyed on the
     * authenticated user id, with an IP fallback purely defensive against
     * a null key silently becoming a global limiter.
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

        RateLimiter::for('api-qr-redeem', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->ip())
            ->response($this->throttled(...)));

        RateLimiter::for('qr-login-mint', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) ($request->user()?->id ?? $request->ip()))
            ->response($this->throttled(...)));
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
