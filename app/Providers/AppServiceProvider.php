<?php

namespace App\Providers;

use App\Logging\RefreshLog;
use App\Models\User;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        // Refresh logs contain account/profile data: open locally, allowlisted users elsewhere
        Gate::define('viewLogViewer', fn (?User $user = null) => $this->app->isLocal()
            || in_array($user?->email, config('logging.viewer_emails'), true));

        Event::listen(fn (ModelsPruned $event) => RefreshLog::info('retention.pruned', [
            'model' => $event->model,
            'count' => $event->count,
            'retention_days' => config('refresh.retention_days'),
        ]));
    }
}
