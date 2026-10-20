<?php

namespace App\Providers;

use App\Logging\RefreshLog;
use App\Models\User;
use App\Refresh\FixedRefreshHandler;
use App\Refresh\LegacyRefreshHandler;
use App\Refresh\RefreshHandler;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolved per job run, so the handler can be switched via config without restarting code paths
        $this->app->bind(RefreshHandler::class, fn ($app) => match (config('refresh.handler')) {
            'fixed' => $app->make(FixedRefreshHandler::class),
            'legacy' => $app->make(LegacyRefreshHandler::class),
            default => throw new InvalidArgumentException('Unknown refresh handler ['.config('refresh.handler').']'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Refresh logs contain account/profile data: open locally, allowlisted users elsewhere
        Gate::define('viewLogViewer', fn (?User $user = null) => $this->app->isLocal()
            || in_array($user?->email, config('logging.viewer_emails'), true));

        // Dashboard + its actions (refresh, block, move): same people as the log viewer
        Gate::define('operate', fn (?User $user = null) => $this->app->isLocal()
            || in_array($user?->email, config('logging.viewer_emails'), true));

        Event::listen(fn (ModelsPruned $event) => RefreshLog::info('retention.pruned', [
            'model' => $event->model,
            'count' => $event->count,
            'retention_days' => config('refresh.retention_days'),
        ]));
    }
}
