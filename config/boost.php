<?php

use App\Mcp\Tools\FinalizeTask;
use App\Mcp\Tools\GetTaskContext;
use App\Mcp\Tools\GetVaultRules;
use App\Mcp\Tools\HandoffTask;
use App\Mcp\Tools\RecordWorkflowOutcome;
use App\Mcp\Tools\RunProjectVerification;
use App\Mcp\Tools\SaveQaReview;
use App\Mcp\Tools\SaveTaskPlan;
use App\Mcp\Tools\SaveTaskResult;
use App\Mcp\Tools\WriteVaultWorkLog;

return [
    'mcp' => [
        'tools' => [
            'include' => [
                GetTaskContext::class,
                GetVaultRules::class,
                WriteVaultWorkLog::class,
                RunProjectVerification::class,
                SaveTaskResult::class,
                SaveTaskPlan::class,
                SaveQaReview::class,
                HandoffTask::class,
                FinalizeTask::class,
                RecordWorkflowOutcome::class,
            ],
        ],
    ],
];
