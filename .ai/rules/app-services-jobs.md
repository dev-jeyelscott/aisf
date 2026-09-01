---
paths:
    - 'app/Services/{AgentExecutionRunner,AgentTurnReconciler}.php,app/Jobs/ProcessAgentExecution.php'
---

# App Services Jobs

## Durable state outranks provider terminal output

Agent terminal output is informational only. AgentTurnReconciler classifies the turn from AgentRunAction plus Task/WorkRequest, review, handoff, outcome, and Git state; satisfied durable actions win even after empty/malformed output or a non-zero provider exit.
