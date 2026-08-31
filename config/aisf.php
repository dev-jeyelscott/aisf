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

];
