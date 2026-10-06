<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Knuckles\Scribe\Scribe;
use Livewire\Livewire;

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

        Livewire::addPersistentMiddleware([
            EnsureUserIsAdmin::class,
        ]);

        $this->configureRateLimiting();
        $this->configureApiDocs();
    }

    /**
     * Scribe (php artisan scribe:generate) builds each Form Request without a route
     * parameter, but UpdateUserRequest needs to know which user is being updated.
     * Give it an in-memory example user (never saved) so its rules can be read.
     */
    protected function configureApiDocs(): void
    {
        Scribe::instantiateFormRequestUsing($this->makeFormRequestForApiDocs(...));
    }

    /**
     * Build a Form Request for Scribe to read its rules from.
     */
    protected function makeFormRequestForApiDocs(string $className): FormRequest
    {
        $formRequest = new $className;

        if (! $formRequest instanceof FormRequest) {
            throw new InvalidArgumentException("{$className} is not a Form Request.");
        }

        if ($formRequest instanceof UpdateUserRequest) {
            $formRequest->forUser((new User)->forceFill(['id' => 1]));
        }

        return $formRequest;
    }

    /**
     * Configure the API rate limiter: 60 requests per minute per user, or per IP when not logged in.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
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
}
