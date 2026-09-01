<?php

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\WorkRequest;
use App\Services\NotionClient;
use App\Services\ProjectAgentProvisioner;
use App\Services\WorkRequestIngestion;
use Illuminate\Support\Facades\Queue;
use UnexpectedValueException;

use function Pest\Laravel\mock;

test('ingesting the same external event twice does not duplicate a WorkRequest', function () {
    Queue::fake();
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $ingestion = app(WorkRequestIngestion::class);

    $first = $ingestion->ingest($project, 'github', 'Fix the bug.', 'org/repo#42', 'https://github.com/org/repo/issues/42');
    $second = $ingestion->ingest($project, 'github', 'Fix the bug.', 'org/repo#42', 'https://github.com/org/repo/issues/42');

    expect($first->is($second))->toBeTrue()
        ->and(WorkRequest::query()->count())->toBe(1);
    Queue::assertPushed(ProcessAgentExecution::class, 1);
});

test('ingestion requires every enabled role before creating a WorkRequest', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $project->agents()->where('role', 'qa')->update(['enabled' => false]);

    expect(fn () => app(WorkRequestIngestion::class)->ingest($project, 'github', 'Fix the bug.', 'org/repo#1'))
        ->toThrow(UnexpectedValueException::class);
    expect(WorkRequest::query()->count())->toBe(0);
});

test('a GitHub webhook with an invalid signature is rejected', function () {
    Queue::fake();
    $project = Project::factory()->create(['github_webhook_secret' => 'shh-secret']);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $this->postJson(route('webhooks.github', $project), ['issue' => ['number' => 1]], [
        'X-GitHub-Event' => 'issues',
        'X-Hub-Signature-256' => 'sha256=not-the-right-signature',
    ])->assertStatus(401);

    expect(WorkRequest::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a correctly signed GitHub issue labeled ready ingests a WorkRequest', function () {
    Queue::fake();
    $project = Project::factory()->create(['github_webhook_secret' => 'shh-secret', 'github_ready_label' => 'ai-ready']);
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $payload = [
        'action' => 'labeled',
        'repository' => ['full_name' => 'org/repo'],
        'issue' => [
            'number' => 7,
            'title' => 'Add dark mode',
            'body' => 'Users want a dark theme.',
            'html_url' => 'https://github.com/org/repo/issues/7',
            'labels' => [['name' => 'ai-ready'], ['name' => 'bug']],
        ],
    ];
    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'shh-secret');

    $this->postJson(route('webhooks.github', $project), $payload, [
        'X-GitHub-Event' => 'issues',
        'X-Hub-Signature-256' => $signature,
    ])->assertStatus(202);

    $workRequest = WorkRequest::query()->sole();
    expect($workRequest->source_type)->toBe('github')
        ->and($workRequest->source_external_id)->toBe('org/repo#7')
        ->and($workRequest->source_url)->toBe('https://github.com/org/repo/issues/7')
        ->and($workRequest->prompt)->toContain('Add dark mode');
    Queue::assertPushed(ProcessAgentExecution::class, fn (ProcessAgentExecution $job) => $job->subject->is($workRequest));

    // A duplicate delivery of the same signed payload must not duplicate the WorkRequest.
    $this->postJson(route('webhooks.github', $project), $payload, [
        'X-GitHub-Event' => 'issues',
        'X-Hub-Signature-256' => $signature,
    ])->assertStatus(202);
    expect(WorkRequest::query()->count())->toBe(1);
});

test('a GitHub issue without the ready label is ignored', function () {
    Queue::fake();
    $project = Project::factory()->create(['github_webhook_secret' => 'shh-secret', 'github_ready_label' => 'ai-ready']);
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $payload = [
        'action' => 'opened',
        'repository' => ['full_name' => 'org/repo'],
        'issue' => ['number' => 3, 'title' => 'Something', 'body' => '', 'html_url' => '', 'labels' => [['name' => 'bug']]],
    ];
    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'shh-secret');

    $this->postJson(route('webhooks.github', $project), $payload, [
        'X-GitHub-Event' => 'issues',
        'X-Hub-Signature-256' => $signature,
    ])->assertStatus(200);

    expect(WorkRequest::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('Notion sync ingests every ready page and is idempotent across polls', function () {
    Queue::fake();
    $project = Project::factory()->create(['notion_database_id' => 'db-1', 'notion_integration_token' => 'secret-token']);
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    mock(NotionClient::class)
        ->shouldReceive('fetchReadyPages')
        ->twice()
        ->andReturn([
            ['external_id' => 'page-1', 'prompt' => 'Ship the onboarding flow.', 'source_url' => 'https://notion.so/page-1'],
        ]);

    $this->artisan('notion:sync')->assertSuccessful();
    $this->artisan('notion:sync')->assertSuccessful();

    $workRequest = WorkRequest::query()->sole();
    expect($workRequest->source_type)->toBe('notion')
        ->and($workRequest->source_external_id)->toBe('page-1');
    Queue::assertPushed(ProcessAgentExecution::class, 1);
});

test('a disabled or unconfigured Project is skipped by Notion sync without error', function () {
    Project::factory()->create(['enabled' => false, 'notion_database_id' => 'db-1', 'notion_integration_token' => 'secret-token']);
    Project::factory()->create(['notion_database_id' => null, 'notion_integration_token' => null]);
    mock(NotionClient::class)->shouldNotReceive('fetchReadyPages');

    $this->artisan('notion:sync')->assertSuccessful();

    expect(WorkRequest::query()->count())->toBe(0);
});
