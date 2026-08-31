<?php

use App\Jobs\ProcessWorkRequest;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;

test('a prompt is persisted as submitted and queued for planning', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $response = $this->post(route('projects.work-requests.store', $project), ['prompt' => 'Add a project overview page.']);

    $response->assertRedirect(route('projects.show', $project));
    expect($project->workRequests()->sole())->status->toBe('submitted');
    Queue::assertPushed(ProcessWorkRequest::class);
});
