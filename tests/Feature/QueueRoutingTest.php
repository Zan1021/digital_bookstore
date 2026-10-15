<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBookClassificationJob;
use App\Jobs\GeneratePageRepairJob;
use App\Jobs\TranslateEditionJob;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Task 5.2 (design §20): work runs on separate queues — intake, ai, layout, exports, mail —
 * so a big translation batch never delays an email; Horizon runs one supervisor per
 * queue group and its dashboard is Super Admin only.
 */
class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_are_routed_to_their_queues(): void
    {
        Queue::fake();

        AnalyzeBookClassificationJob::dispatch(1);
        TranslateEditionJob::dispatch(1);
        GeneratePageRepairJob::dispatch(1, 1, 1);

        Queue::assertPushedOn('intake', AnalyzeBookClassificationJob::class);
        Queue::assertPushedOn('ai', TranslateEditionJob::class);
        Queue::assertPushedOn('ai', GeneratePageRepairJob::class);
    }

    public function test_staff_invitations_are_sent_from_the_mail_queue(): void
    {
        Queue::fake();

        User::factory()->create()->notify(new StaffInvitation('token', 'Support'));

        Queue::assertPushedOn('mail', SendQueuedNotifications::class);
    }

    public function test_every_queue_has_a_horizon_supervisor_in_every_environment(): void
    {
        $covered = collect(config('horizon.defaults'))->pluck('queue')->flatten()->all();
        foreach (['intake', 'ai', 'layout', 'exports', 'mail', 'default'] as $queue) {
            $this->assertContains($queue, $covered, "no Horizon supervisor works the '$queue' queue");
        }
        foreach (config('horizon.environments') as $env => $supervisors) {
            $this->assertEqualsCanonicalizing(array_keys(config('horizon.defaults')), array_keys($supervisors),
                "Horizon environment '$env' does not run every supervisor");
        }
    }

    public function test_redis_retry_after_exceeds_the_longest_job_timeout(): void
    {
        $longest = max(array_map(fn ($job) => (new \ReflectionClass($job))->getDefaultProperties()['timeout'] ?? 60,
            [AnalyzeBookClassificationJob::class, TranslateEditionJob::class, GeneratePageRepairJob::class]));

        $this->assertGreaterThan($longest, config('queue.connections.redis.retry_after'),
            'a job outliving retry_after is started a second time (double AI spend)');
        foreach (config('horizon.defaults') as $name => $supervisor) {
            $this->assertLessThan(config('queue.connections.redis.retry_after'), $supervisor['timeout'], $name);
        }
    }

    public function test_the_horizon_dashboard_is_super_admin_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->get('/horizon')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create()->assignRole('Content Manager'))->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole('Super Admin'))->get('/horizon')->assertOk();
    }
}
