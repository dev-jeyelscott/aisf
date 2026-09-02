---
paths:
    - app/Services/AgentHarness.php
    - app/Services/WorkflowDispatcher.php
---

# App Services

## Codex/Claude harness must always wire the Boost MCP server explicitly

Every Codex `exec` and Claude `--print` invocation runs with cwd set to the target Project repository (not this app), so it never sees this app's .mcp.json. AgentExecutionRunner currently executes PM, Coder, and QA turns directly in the Project repository; the Task worktree creation service remains available for a future execution mode. The AgentExecutionRunner contract tells PM/Coder/QA to call save_task_plan, handoff_task, get_task_context, save_task_result, save_qa_review — but without explicit wiring those tools are unreachable, and the Agent silently falls back to producing free-form output that fails AgentExecutionRunner::parseCompletion's schema check (or a degraded response that skips persistence).

Fix: AgentHarness::executeCodex passes `-c mcp_servers.boost.command="php" -c mcp_servers.boost.args=[...]`, and executeClaude passes `--mcp-config '{"mcpServers":{"boost":{...}}}'`, both built from `boostMcpArgs()` using `base_path('artisan')` so it resolves regardless of the subprocess cwd. If you add a new harness/provider, replicate this wiring or the same silent-tool-loss failure mode recurs.

## Recover missing Task handoff projections

Before dispatching eligible pending or waiting Tasks, restore last_handoff from the latest durable TaskHandoff when the projection is missing. This repairs interrupted retries while preserving handoff-driven eligibility.

## Keep Codex resume flags separate from initial execution flags

Codex `exec resume` has a narrower option set than initial `exec` (notably no `--sandbox`, `--color`, or `--output-schema` in the installed CLI). Build resume commands separately and test against the installed CLI help; otherwise retries fail immediately with exit code 2 before the agent starts.
