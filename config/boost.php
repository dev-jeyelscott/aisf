<?php

use App\Mcp\Tools\GetTaskContext;
use App\Mcp\Tools\HandoffTask;
use App\Mcp\Tools\SaveQaReview;
use App\Mcp\Tools\SaveTaskPlan;
use App\Mcp\Tools\SaveTaskResult;

return [
    'mcp' => [
        'tools' => [
            'include' => [
                GetTaskContext::class,
                SaveTaskResult::class,
                SaveTaskPlan::class,
                SaveQaReview::class,
                HandoffTask::class,
            ],
        ],
    ],
];
