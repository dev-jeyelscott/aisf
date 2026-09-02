---
paths:
    - 'app/Jobs/*.php,app/Services/AgentExecutionRunner.php,app/Console/Commands/DispatchWorkflow.php'
---

# Console Commands

## Durable role-handoff workflow: coarse states, Laravel owns infrastructure

This app was refactored so Laravel is a dispatch, execution, persistence, authorization, verification, and audit layer. Do not recreate a fixed Laravel PM/Coder/QA engineering state machine.

Task and WorkRequest status use the coarse `pending`, `running`, `waiting`, `completed`, `failed`, and `cancelled` vocabulary. `Task.blocked_reason` and `WorkRequest.failure_reason` hold terminal failure reasons.

Manual WorkRequest Retry re-enters `pending`, resets its protocol recovery state, and clears its WorkRequest-level handoff state. Manual Task Retry re-enters `pending`, resets protocol recovery state, starts a fresh bounded Task repair episode, and preserves or reconstructs the latest durable Task handoff required for redispatch. Historical AgentRuns, reviews, handoffs, verification evidence, and actions are never deleted to implement Retry.

`ProcessAgentExecution` is the single Job for all Agent work. The configured Project Manager plans WorkRequests, the configured Coder implements Tasks, and the configured QA independently reviews candidates. Agents request durable handoffs. Laravel persists and validates their integrity but does not dictate engineering judgment. Each role resolves its own enabled ProjectAgent harness and model at run time. Do not add role-specific queue Jobs.

AISF independently owns worktrees, immutable candidate identity, host-controlled verification, pull requests, review evidence, merge policy, and merge gates. A code-producing ProjectAgent cannot approve its own candidate. Recoverable engineering failures become bounded fresh Agent turns with durable evidence. Queue retries are for infrastructure failures only.

`ProjectVerificationService` is the only authority permitted to execute operator-approved Project verification profiles. Agents never invent executable CI commands, and finalization must not introduce a second hard-coded verification command.

`DispatchWorkflow` reconciles pending and waiting WorkRequests and Tasks whose dependencies are complete while preserving one active execution per Project. A WorkRequest starts only the Project Manager. A Task starts only from its latest accepted durable handoff.
