<?php

namespace App\Services;

use App\Models\AgentRun;

final readonly class AgentTurnExecution
{
    public function __construct(
        public AgentRun $run,
        public AgentHarnessResult $harnessResult,
        public string $summary,
    ) {}
}
