---
paths:
  - app/Services/AgentHarness.php
---

# App Services

## Codex/Claude harness must always wire the Boost MCP server explicitly
Every Codex `exec` and Claude `--print` invocation runs with cwd set to the target repository/worktree (not this app), so it never sees this app's .mcp.json. AgentExecutionRunner's contract tells PM/Coder/QA to call save_task_plan, handoff_task, get_task_context, save_task_result, save_qa_review — but without explicit wiring those tools are unreachable, and the Agent silently falls back to producing free-form output that fails AgentExecutionRunner::parseCompletion's schema check (or a degraded response that skips persistence).

Fix: AgentHarness::executeCodex passes `-c mcp_servers.boost.command="php" -c mcp_servers.boost.args=[...]`, and executeClaude passes `--mcp-config '{"mcpServers":{"boost":{...}}}'`, both built from `boostMcpArgs()` using `base_path('artisan')` so it resolves regardless of the subprocess cwd. If you add a new harness/provider, replicate this wiring or the same silent-tool-loss failure mode recurs.
