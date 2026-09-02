<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task Worktree Base Path
    |--------------------------------------------------------------------------
    |
    | Isolated Task Git worktrees are created under this directory, one per
    | Task ID. Tests must not share this path with the real application.
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
    | Obsidian Documentation Vault
    |--------------------------------------------------------------------------
    |
    | Local documentation governance is read from this configured Obsidian
    | vault. Application code must resolve and validate this path before use.
    | Tests must override it with an isolated temporary vault.
    |
    */

    'obsidian_vault_path' => env('AISF_OBSIDIAN_VAULT_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Project Verification
    |--------------------------------------------------------------------------
    |
    | Docker verification definitions must live outside managed Project
    | repositories so an Agent cannot mutate infrastructure policy through
    | repository writes. Native verification remains an explicit trusted-host
    | escape hatch and is disabled by default.
    |
    */

    'verification_definition_path' => env(
        'AISF_VERIFICATION_DEFINITION_PATH',
        storage_path('app/verification-definitions'),
    ),

    'allow_trusted_native_verification' => filter_var(
        env('AISF_ALLOW_TRUSTED_NATIVE_VERIFICATION', false),
        FILTER_VALIDATE_BOOL,
    ),

    'verification_max_timeout' => max(
        1,
        (int) env('AISF_VERIFICATION_MAX_TIMEOUT', 1800),
    ),

    'verification_output_limit' => max(
        1000,
        (int) env('AISF_VERIFICATION_OUTPUT_LIMIT', 12000),
    ),

    /*
    |--------------------------------------------------------------------------
    | Maximum QA <-> Coder Repair Cycles
    |--------------------------------------------------------------------------
    |
    | A Task's repair cycle count is the number of QA "changes_requested"
    | reviews plus CI-failure repair handoffs it has accumulated. Once this
    | limit is reached, the next repair handoff durably fails the Task
    | (blocked_reason explains why) instead of looping forever. An operator
    | must Retry it after intervening.
    |
    */

    'max_repair_cycles' => (int) env('AISF_MAX_REPAIR_CYCLES', 5),

    /*
    |--------------------------------------------------------------------------
    | Maximum Protocol Recoveries
    |--------------------------------------------------------------------------
    |
    | Missing durable postconditions receive fresh Agent turns without consuming
    | QA/CI engineering repair cycles or infrastructure queue retries.
    |
    */

    'max_protocol_recoveries' => (int) env('AISF_MAX_PROTOCOL_RECOVERIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Trusted Local Agent Execution
    |--------------------------------------------------------------------------
    |
    | When enabled, an Agent's own Codex/Claude subprocess may use the AISF
    | worker user's pre-existing host Docker access directly, the same as a
    | developer's terminal session, instead of being forced through
    | run_project_verification for every Docker-dependent check. Only enable
    | this for a worker deployment where every configured Project is fully
    | trusted. The Docker-sandboxed verification bridge above remains
    | available regardless of this flag.
    |
    | agent_runtime_path/agent_runtime_home let the queue worker's Codex/Claude
    | subprocess resolve the same PATH/HOME a developer's interactive shell
    | would (Volta/NVM-managed binaries included), independent of this flag.
    | Leave unset to inherit the worker process's ambient environment.
    |
    */

    'trusted_local_execution' => filter_var(
        env('AISF_TRUSTED_LOCAL_EXECUTION', false),
        FILTER_VALIDATE_BOOL,
    ),

    'agent_runtime_path' => env('AISF_AGENT_PATH'),

    'agent_runtime_home' => env('AISF_AGENT_HOME'),

    /*
    |--------------------------------------------------------------------------
    | Agent Turn Timeout
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds a single Codex/Claude provider turn may
    | run before AISF treats it as an infrastructure failure. Without this,
    | a dead or hung provider/MCP subprocess left the AgentRun permanently
    | stuck in "running" instead of failing cleanly through the existing
    | durable reconciliation and repair path. Keep this comfortably below
    | the queue connection's retry_after (queue.php / DB_QUEUE_RETRY_AFTER)
    | so a turn always finishes reconciling before the queue driver would
    | otherwise consider the job's reservation abandoned and release it
    | for a second, doomed attempt.
    |
    */

    'agent_turn_timeout' => max(60, (int) env('AISF_AGENT_TURN_TIMEOUT', 3300)),

];
