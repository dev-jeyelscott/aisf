<?php

namespace App\Jobs;

use App\Models\WorkRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWorkRequest implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public WorkRequest $workRequest) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->workRequest->update(['status' => 'processing']);
    }
}
