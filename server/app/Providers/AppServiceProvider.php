<?php

namespace App\Providers;

use App\Models\Invitation;
use App\Models\Topic;
use App\Models\User;
use App\Support\AccountQuota;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        $this->app->make(Settings::class)->applyToConfig();

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        Paginator::defaultView('pagination.default');
        Paginator::defaultSimpleView('pagination.default');

        $this->configureRateLimiting();

        // Counters and status shown in the sidebar.
        View::composer('layouts.navigation', function ($view) {
            $view->with('nav', once(function () {
                $user = auth()->user();

                return [
                    'topics' => Topic::query()->readableBy($user)->count(),
                    'invitations' => $user?->isAdmin() ? Invitation::query()->pending()->count() : 0,
                    'push' => $this->app->make(Settings::class)->pushConfigured(),
                    'relay' => $user?->isAdmin() && $this->app->make(Settings::class)->relayServerActive(),
                    // Hosted mode: today's usage of the account (not for admins).
                    'quota' => AccountQuota::enabled() && $user && ! $user->isAdmin()
                        ? ['used' => $user->messagesToday(), 'limit' => $user->effectiveDailyLimit()]
                        : null,
                ];
            }));
        });

        // Long running queue workers must pick up settings changed from the
        // administration UI (e.g. a new SMTP server) without a restart.
        Event::listen(JobProcessing::class, function () {
            $settings = $this->app->make(Settings::class);
            $settings->refresh();
            $settings->applyToConfig();
            Mail::forgetMailers();
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('publish', fn (Request $request) => Limit::perMinute(60)
            ->by('publish:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('relay-register', fn (Request $request) => Limit::perHour(5)->by('relay:'.$request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
