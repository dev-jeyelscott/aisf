---
paths:
    - 'app/Mcp/Tools/*.php,app/Services/TaskContextBuilder.php,app/Services/TaskWorkflowService.php,app/Models/TaskHandoff.php'
---

# Agent Handoffs

Task state remains coarse. Agent-facing operations must be scoped to an active AgentRun, Project, and configured ProjectAgent; mutations need an idempotency key and must reject stale runs. PM and QA are repository read-only; only Coder may write the Project repository. Task worktree creation remains available for a future execution mode. Do not add role-specific jobs or use provider conversation history as workflow truth.
