<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Domains\Platform\Support\SupportAccess::class);
        $this->app->scoped(\App\Domains\Platform\Features\Features::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hidden extra queries (lazy loading) fail in development and tests;
        // in production they are only logged, never shown to a hotel (S16).
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();
        if ($this->app->isProduction()) {
            \Illuminate\Database\Eloquent\Model::handleLazyLoadingViolationUsing(function ($model, string $relation): void {
                \Illuminate\Support\Facades\Log::warning('Lazy loading', ['model' => $model::class, 'relation' => $relation]);
            });
        }

        // Inside a support session, hotel permissions come from the session.
        \Illuminate\Support\Facades\Gate::before(function ($user, string $ability): ?bool {
            $support = app(\App\Domains\Platform\Support\SupportAccess::class);

            if ($support->current() === null || \App\Domains\Access\Permission::tryFrom($ability) === null) {
                return null;
            }

            return $support->allows($ability);
        });
        $this->configureDefaults();
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

        Password::defaults(function (): Password {
            $rule = Password::min((int) config('security.password_min_length', 12))
                ->letters()
                ->numbers();

            return config('security.check_breached_passwords') === true
                ? $rule->uncompromised()
                : $rule;
        });
    }
}
