# AGENTS.md

## AGEAX AI Powered Software Factory

This repository is **AGEAX AI Powered Software Factory, AISF**, an autonomous software engineering system built around durable Laravel-owned workflow orchestration and disposable AI Agent execution.

Agents may reason, inspect, implement, review, test, and recommend. **Laravel remains authoritative for authorization, workflow state, execution ownership, persistence, reconciliation, Git lifecycle, verification, recovery, and auditing.**

Do not introduce architecture that transfers those responsibilities to an LLM, provider session, prompt, or unstructured agent output.

---

## 1. Instruction Priority

Follow instructions in this order:

1. Current user request and explicit acceptance criteria.
2. Repository `AGENTS.md`.
3. `CLAUDE.md` when applicable.
4. `.ai/rules/index.md` and every applicable `.ai/rules/**` rule.
5. Task-specific specifications, ADRs, implementation plans, and project documentation.
6. Existing implementation, tests, database schema, configuration, and established conventions.
7. Official framework and package documentation.
8. General engineering knowledge.

When documentation conflicts with the current implementation, investigate before changing behavior. Do not silently choose one interpretation.

---

## 2. Mandatory Repository Inspection

Before planning or modifying code:

1. Read this file.
2. Read `CLAUDE.md` when applicable.
3. Read `.ai/rules/index.md`.
4. Determine every rule file whose glob covers the files in scope.
5. Search `.ai/rules/**` for concepts relevant to the task so non-path-specific constraints are not missed.
6. Inspect the current implementation and its neighboring files.
7. Inspect existing tests covering the affected behavior.
8. Confirm installed dependency versions before relying on version-specific APIs.
9. Activate applicable repository skills before working in their domain.

Do not implement from assumptions or from an older description of the repository.

Repository state is authoritative.

---

## 3. Core Architectural Invariants

### 3.1 Laravel Owns Durable Truth

Provider output, terminal text, conversational history, and model reasoning are not workflow authority.

Durable AISF state is authoritative, including where applicable:

- `AgentRun`
- `AgentRunAction`
- `AgentSession`
- `WorkRequest`
- `Task`
- `TaskHandoff`
- candidate fingerprints
- candidate reviews
- QA reviews
- workflow outcomes
- project verification evidence
- Git state
- execution metadata

A provider process may fail after already performing the required durable action. Reconciliation must therefore evaluate durable state rather than blindly treating provider exit status or terminal text as the final outcome.

### 3.2 Agent Execution Is Disposable

Agent processes and provider sessions may terminate, time out, crash, retry, or lose conversational context.

The system must remain recoverable from persisted AISF state.

Never make correctness depend on:

- an Agent remembering an earlier turn
- provider conversation history
- terminal output being parseable
- a process surviving indefinitely
- hidden model state

### 3.3 Do Not Create a Parallel Workflow Engine

Extend the existing Laravel workflow instead of creating another state system in:

- provider prompts
- shell scripts
- agent memory
- filesystem markers
- arbitrary JSON blobs
- background processes
- provider-specific sessions

Keep state transitions deterministic, auditable, and owned by AISF.

---

## 4. Agent Execution Contract

Agent-facing operations must be scoped to the active:

- Project
- configured `ProjectAgent`
- `AgentRun`
- valid execution token

Mutating Agent operations must preserve stale-run protection and idempotency where required.

Do not allow a previous or stale AgentRun to mutate the current workflow.

Do not grant an Agent additional authority merely because the Agent asks for it.

Execution capabilities must be determined by AISF configuration and the current execution environment.

---

## 5. Agent Roles and Repository Permissions

Current core engineering roles include Project Manager, Coder, and QA.

### Project Manager

The PM may:

- inspect repository context required for planning
- create or save structured implementation plans
- create required documentation evidence
- hand work to the appropriate configured Agent

The PM is **repository read-only**.

Do not give the PM repository write permission to simplify orchestration.

### Coder

The Coder may:

- inspect the repository
- modify the authorized project working tree
- run appropriate implementation verification
- persist implementation evidence
- create required documentation evidence
- hand the candidate to QA

The Coder is the role permitted to modify the project repository during implementation.

### QA

QA may:

- inspect the repository and candidate
- run or request authorized verification
- persist review evidence
- approve or request changes
- create required documentation evidence
- hand the Task through the existing workflow

QA is **repository read-only**.

QA should verify the candidate rather than silently fixing implementation defects.

### Future Agents

Do not implement role behavior by creating separate orchestration pipelines when the generic Agent contract can express it.

New Agents should inherit the same fundamentals:

- active AgentRun authorization
- execution-token validation
- durable actions
- idempotent mutations
- stale-run rejection
- capability restrictions
- deterministic reconciliation

---

## 6. Task Handoffs

Task state should remain coarse.

Use durable `TaskHandoff` evidence to represent Agent-to-Agent workflow movement rather than proliferating role-specific Task statuses.

Handoffs must:

- originate from the correct active AgentRun
- target an authorized configured Agent
- preserve current Project and Task identity
- satisfy required prior evidence
- reject stale execution
- remain auditable
- be idempotent where required

Do not use provider conversation history as handoff state.

Do not introduce role-specific jobs when the generic Agent execution path already supports the workflow.

---

## 7. Candidate Identity

A Task candidate is identified by its immutable **`candidate_tree_sha`**.

This identity must be preserved across:

- Coder completion
- QA review
- verification
- approval
- finalization
- integration

Never authorize a candidate using evidence belonging to:

- another Task
- another Project
- another tree SHA
- another verification profile
- another verification target
- a stale checkout
- a previous candidate version

When candidate contents change, previous approval or verification must not automatically apply to the new candidate.

---

## 8. QA and Acceptance

Current-candidate evidence is authoritative.

Do not restore historical logic where any old `changes_requested` review permanently invalidates a Task.

The latest relevant review for the current immutable candidate must drive acceptance.

Before finalization, verify that approval still belongs to the exact current candidate.

---

## 9. Project Verification

`ProjectVerificationService` is the authoritative project verification execution path.

For CI-gated finalization:

- use the configured operator-approved `ci` profile
- bind evidence to the exact Project, Task, target, profile, and `candidate_tree_sha`
- reuse valid decisive verification evidence when appropriate
- request new verification through `ProjectVerificationService` when required

Do not introduce an independent hard-coded CI command in finalization.

In particular, do not make another service separately execute `composer ci:check` as a competing finalization authority.

Verification statuses must retain their semantics:

- `passed`: may authorize progress when all other invariants remain satisfied
- `failed`: represents a candidate verification failure and may enter the bounded repair workflow
- `stale_candidate`: never authorizes finalization
- `environment_unavailable`: infrastructure or environment failure, not automatically a code defect
- `timed_out`: inconclusive unless existing policy explicitly says otherwise
- running or incomplete states: do not authorize finalization

`ProjectVerificationRun` is durable intermediate evidence. It must not independently mutate Task workflow state.

---

## 10. Repair Cycles

Autonomous Coder and QA repair activity is bounded.

`RepairCycleGuard` evaluates the active operator-approved repair episode.

Historical reviews and handoffs are immutable audit evidence and must not be deleted merely to reset the current repair budget.

An explicit operator Retry may begin a fresh repair-cycle budget according to the existing Task-scoped boundary mechanism.

Automatic QA-to-Coder or CI-failure handoffs must not silently reset that boundary.

Environment-only failures must not be mislabeled as code defects or incorrectly consume Coder repair budget.

---

## 11. Finalization

A Task reaches `completed` only through the authoritative finalization path.

Finalization must preserve all applicable gates, including:

- current candidate identity
- current candidate approval
- required Agent-authored documentation evidence
- authoritative project verification
- current worktree validation
- final commit tree validation
- verified Git integration
- durable AgentRunAction evidence
- WorkRequest completion behavior

Never mark a Task completed merely because an Agent claims it is complete.

Never bypass finalization gates to recover from a provider failure.

---

## 12. Vault Documentation

When the current workflow requires Agent-authored vault documentation, treat it as a workflow invariant rather than prompt etiquette.

Agent documentation must remain Agent-authored Markdown where required.

Do not replace Agent-authored content with a hard-coded AISF prose template.

Preserve:

- vault-root confinement
- `.md` destination validation
- execution-token authorization
- applicable `AGENTS.md` rule discovery
- idempotent writes
- one logical note per AgentRun where required
- durable note metadata or hash evidence
- no unnecessary duplication of the full note body into AISF database artifacts

Documentation evidence must exist before workflow operations that explicitly require it.

---

## 13. Git and Worktree Safety

Git operations are workflow-critical.

Preserve repository isolation, candidate identity, branch correctness, and existing worktree conventions.

Before destructive or integrating Git operations:

- verify the expected repository
- verify the expected Task
- verify the expected branch or worktree
- verify candidate identity
- reject stale state

Do not:

- force-push unless explicitly required and authorized
- rewrite unrelated history
- discard unrelated working-tree changes
- use `git reset --hard` as a generic recovery mechanism
- bypass candidate verification
- commit unrelated files
- silently modify another Task's worktree

Fix Git failures at their actual source.

---

## 14. Backend Engineering Standards

This application currently uses Laravel 13 with PHP 8.x and Pest.

Follow Laravel conventions and the repository's current implementation.

### PHP

- Use explicit parameter and return types.
- Use constructor property promotion where appropriate.
- Always use braces for control structures.
- Prefer descriptive method and variable names.
- Prefer PHPDoc for useful type information or non-obvious contracts.
- Avoid unnecessary inline comments.
- Keep services focused.
- Avoid hidden global state.
- Preserve transactions and locking where required.
- Validate all external input.
- Enforce authorization server-side.
- Preserve idempotency for retryable operations.

### Laravel

Prefer framework-native mechanisms and existing dependencies.

Do not add dependencies without approval.

Use official documentation through Laravel Boost `search-docs` when behavior depends on framework or package APIs.

Inspect schema before modifying persistence behavior.

Use Eloquent and existing repository conventions instead of introducing unnecessary abstraction layers.

---

## 15. Frontend Engineering Standards

The current frontend uses:

- Inertia 3
- React 19
- TypeScript
- Tailwind CSS 4
- Radix UI
- shadcn-style components
- Wayfinder
- Vite Plus

Before frontend work:

- activate the applicable Inertia React skill
- inspect existing shared UI components
- inspect established design tokens
- reuse current interaction patterns
- preserve responsive behavior
- preserve accessibility and keyboard behavior

Use Wayfinder for Laravel route integrations where the repository currently expects it.

Do not duplicate shared components or introduce a second design system.

---

## 16. Testing Requirements

Every behavioral code change requires focused regression coverage.

Before writing tests, activate `testing-best-practices`.

Prefer feature tests unless a unit test is clearly more appropriate.

Tests should verify:

- expected success behavior
- important failure modes
- authorization boundaries
- stale execution protection where applicable
- idempotency where applicable
- candidate identity where applicable
- durable state transitions
- recovery behavior

Use factories and existing test helpers.

Do not delete tests simply because they fail after a change.

Do not weaken assertions to make a regression disappear.

Do not claim a test passed unless it was actually executed successfully.

Run the narrowest relevant tests during implementation, then broader verification appropriate to the scope.

Typical commands include:

```bash
php artisan test --compact tests/Feature/RelevantTest.php
vendor/bin/pint --dirty --format agent
npm run check
npm run types:check
composer ci:check
```

Use repository Docker aliases when they are configured for the active environment.

Do not assume Docker is available merely because a project can run in Docker. Detect and respect the execution environment.

---

## 17. Static Analysis and Formatting

For modified PHP:

```bash
vendor/bin/pint --dirty --format agent
```

For frontend validation:

```bash
npm run check
npm run types:check
```

For PHP static analysis, use the repository-configured command.

Do not suppress legitimate static-analysis, lint, or type errors just to obtain a green build.

Fix the root cause.

---

## 18. Database Changes

Database changes must be safe for existing environments.

Before modifying schema:

- inspect the current migration history
- inspect the current model
- inspect existing constraints and indexes
- identify existing production data implications

Prefer additive migrations.

Do not rewrite old migrations that may already have been executed unless explicitly instructed.

Preserve foreign keys, uniqueness, audit history, and transactional integrity.

Never delete historical workflow evidence simply to simplify current-state logic.

---

## 19. Security Boundaries

Treat Agent input and Agent output as untrusted.

Validate and authorize server-side.

Preserve:

- Project isolation
- AgentRun scope
- execution-token validation
- stale-run protection
- path confinement
- repository permissions
- role capabilities
- command boundaries
- idempotency
- durable audit evidence

Do not allow Agents to:

- expand their own permissions
- choose unauthorized execution targets
- access arbitrary filesystem locations
- bypass Laravel authorization
- bypass verification gates
- mutate workflow state solely through prose
- treat shell success as equivalent to durable workflow success

---

## 20. Failure and Recovery

Assume failures will happen.

Differentiate:

- implementation defect
- provider failure
- protocol failure
- stale candidate
- stale AgentRun
- environment failure
- verification failure
- infrastructure timeout
- Git integration failure

Do not collapse materially different failures into a generic `failed` path if doing so changes repair accounting, authorization, or operator recovery behavior.

Persist enough structured evidence for deterministic retry and diagnosis.

Recovery should resume from durable state rather than recreate completed work.

---

## 21. Implementation Discipline

For every change:

1. Understand the requested outcome and acceptance criteria.
2. Read all applicable repository rules.
3. Inspect the current implementation.
4. Trace the complete affected workflow.
5. Identify the root cause.
6. Implement the smallest correct change.
7. Add focused regression coverage.
8. Run relevant verification.
9. Review security, concurrency, idempotency, and recovery implications.
10. Report what was actually verified.

Avoid:

- speculative abstractions
- unrelated refactors
- duplicate services
- duplicate workflow paths
- provider-specific architecture leaking into domain logic
- unnecessary schema changes
- unnecessary dependencies
- fixing symptoms instead of root causes

---

## 22. Repository Rule Maintenance

`.ai/rules/**` contains durable, repository-specific engineering decisions.

Always inspect `.ai/rules/index.md` before modifying covered files.

When a code change intentionally changes a settled invariant, update the corresponding rule so repository guidance does not become stale.

Do not create contradictory guidance between `AGENTS.md`, `.ai/rules/**`, tests, and implementation.

More specific `.ai/rules/**` guidance overrides general guidance in this file for its covered paths.

---

## 23. Documentation

Only create new documentation when explicitly requested or required by the task.

Keep documentation aligned with actual implementation.

Do not describe planned behavior as already implemented.

Do not fabricate verification evidence.

When documenting architecture, distinguish clearly between:

- current behavior
- planned behavior
- assumptions
- recommendations

---

## 24. Completion Standard

Before declaring work complete, confirm:

- requested scope is implemented
- applicable repository rules were followed
- existing architecture was preserved
- authorization remains correct
- durable state remains authoritative
- idempotency and retries remain safe
- stale AgentRuns cannot mutate active work
- candidate identity remains exact where applicable
- verification evidence belongs to the correct candidate
- historical audit evidence remains intact
- focused tests pass
- relevant static analysis and formatting pass
- frontend checks pass when frontend code changed
- no unrelated files were modified
- no unsupported claims of testing or execution are made

If verification cannot run because of the environment, report that as an environment limitation. Do not represent it as a successful verification or a code failure.

---

## 25. Final Directive

**Inspect first. Follow `.ai/rules`. Keep Laravel authoritative. Treat LLM execution as disposable and durable state as truth. Preserve AgentRun authorization, role boundaries, idempotency, candidate identity, verification authority, Git safety, bounded recovery, and auditability. Make the smallest production-ready change, test the behavior, and never bypass a workflow invariant merely to make an Agent run appear successful.**

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
