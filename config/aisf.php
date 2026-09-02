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
    | Each operator retry starts a fresh bounded autonomous repair episode.
    | Within that active episode, each QA changes_requested review and each
    | authoritative CI-failure handoff consumes one repair cycle. Historical
    | evidence remains durable and queryable but does not consume a later
    | operator-approved retry budget.
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

];
