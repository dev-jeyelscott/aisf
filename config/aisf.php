<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Worktree Base Path
    |--------------------------------------------------------------------------
    |
    | Isolated Task Git worktrees are created under this directory, one per
    | Task ID. Tests must not share this path with the real application —
    | Task IDs are not globally unique across the dev database and an
    | in-memory test database, so a concurrent test run using the real path
    | can silently overwrite a live worktree mid-execution.
    |
    */

    'worktree_base_path' => env(
        'AISF_WORKTREE_BASE_PATH',
        storage_path(env('APP_ENV') === 'testing' ? 'framework/testing/worktrees' : 'app/worktrees'),
    ),

    /*
    |--------------------------------------------------------------------------
    | Maximum QA <-> Coder Repair Cycles
    |--------------------------------------------------------------------------
    |
    | A Task's repair cycle count is the number of QA "changes_requested"
    | reviews plus CI-failure repair handoffs it has accumulated. Once this
    | limit is reached, the next repair handoff durably fails the Task
    | (blocked_reason explains why) instead of looping forever — an operator
    | must Retry it after intervening.
    |
    */

    'max_repair_cycles' => (int) env('AISF_MAX_REPAIR_CYCLES', 5),

];
