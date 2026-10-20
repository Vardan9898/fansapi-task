<?php

namespace Tests\Feature;

use App\Actions\MoveProfiles;
use App\Enums\RefreshOutcome;
use App\Logging\RedactSensitiveData;
use App\Logging\RefreshLog;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

class LoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_lines_inside_for_profile_carry_account_profile_job_and_attempt(): void
    {
        $log = $this->captureRefreshLog();
        $profile = Profile::factory()->create(['username' => 'madison420ivy']);

        RefreshLog::forProfile($profile, function () {
            RefreshLog::info('fetch.started');
        }, jobUuid: 'job-123', attempt: 2);

        $record = $log->getRecords()[0];
        $this->assertSame('fetch.started', $record->message);
        $this->assertSame([
            'account_id' => $profile->account_id,
            'profile_id' => $profile->id,
            'username' => 'madison420ivy',
            'job_uuid' => 'job-123',
            'attempt' => 2,
        ], $record->extra);
    }

    public function test_context_is_restored_after_for_profile(): void
    {
        Context::add('run_id', 'workload-1');
        $profile = Profile::factory()->create();

        RefreshLog::forProfile($profile, fn () => null, jobUuid: 'job-123');

        $this->assertSame(['run_id' => 'workload-1'], Context::all());
    }

    public function test_real_channel_redacts_secrets(): void
    {
        $log = $this->captureRefreshLog();

        RefreshLog::debug('fetch.request', ['headers' => ['cookie' => 'sess=abc', 'sign' => 'xyz']]);

        $this->assertSame(
            ['cookie' => RedactSensitiveData::MASK, 'sign' => RedactSensitiveData::MASK],
            $log->getRecords()[0]->context['headers'],
        );
    }

    public function test_account_and_move_events_are_logged(): void
    {
        $log = $this->captureRefreshLog();
        $from = Account::factory()->create();
        $to = Account::factory()->create();
        Profile::factory()->for($from)->create();

        $from->block('upstream 403');
        app(MoveProfiles::class)->handle($from, $to);

        $this->assertTrue($log->hasWarningThatContains('account.blocked'));
        $this->assertTrue($log->hasInfoThatContains('profile.moved'));
    }

    public function test_log_viewer_is_open_locally(): void
    {
        $this->app['env'] = 'local';

        $this->get('/log-viewer')->assertOk();
    }

    public function test_log_viewer_is_closed_to_guests_outside_local(): void
    {
        $this->get('/log-viewer')->assertForbidden();
    }

    public function test_log_viewer_allows_allowlisted_users_outside_local(): void
    {
        config(['logging.viewer_emails' => ['ops@example.com']]);

        $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))
            ->get('/log-viewer')->assertOk();

        $this->actingAs(User::factory()->create(['email' => 'other@example.com']))
            ->get('/log-viewer')->assertForbidden();
    }

    public function test_prune_removes_attempts_older_than_retention_and_logs_it(): void
    {
        config(['refresh.retention_days' => 7]);
        $log = $this->captureRefreshLog();
        $profile = Profile::factory()->create();
        $make = fn ($createdAt) => RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'handler' => 'fixed',
            'outcome' => RefreshOutcome::Updated,
        ])->forceFill(['created_at' => $createdAt])->save();

        $make(now()->subDays(8));
        $make(now()->subDays(6));

        $this->artisan('model:prune', ['--model' => [RefreshAttempt::class]])->assertSuccessful();

        $this->assertSame(1, RefreshAttempt::count());
        $this->assertTrue($log->hasInfoThatContains('retention.pruned'));
    }

    public function test_pruning_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'model:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events->first()->expression);
    }
}
