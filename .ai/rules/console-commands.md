---
paths:
    - 'app/Jobs/*.php,app/Services/AgentExecutionRunner.php,app/Console/Commands/DispatchWorkflow.php'
---

# Console Commands

## Durable role-handoff workflow: coarse states, Laravel owns infra only

This app was refactored (docs/09-agent-owned-workflow-refactor.md) so Laravel is only a dispatch/execution/persistence/audit layer. Do NOT reintroduce Laravel-side validation of how work is planned, implemented, tested, reviewed, or committed — that broke real Agent runs before (see docs/09 "Exhibit A").

Task/WorkRequest.status is coarse: pending → running → waiting → completed/failed/cancelled. Task.blocked_reason and WorkRequest.failure_reason hold the terminal 'failed' reason (different column names — see ProcessAgentExecution::failureReasonColumn()). Manual Retry always re-enters 'pending' and clears last_handoff — there is no fine-grained recovery-state mapping anymore.

ProcessAgentExecution is the single Job for all Agent work. The configured Project Manager plans WorkRequests, the configured Coder implements Tasks, and the configured QA independently reviews candidates. Agents request durable handoffs; Laravel persists and validates their integrity but does not dictate engineering judgment. Each role resolves its own enabled ProjectAgent harness/model at run time. Do not add role-specific queue Jobs or recreate a fixed Laravel PM/Coder/QA state machine.

AISF independently owns worktrees, exact-SHA CI, pull requests, review evidence, merge policy, and merge gates. A code-producing ProjectAgent cannot approve its own candidate. Recoverable engineering failures become bounded fresh Foreman turns with durable evidence; queue retries are for infrastructure failures only.

DispatchWorkflow (`workflow:dispatch`, scheduled every minute) reconciles pending/waiting WorkRequests and Tasks whose dependencies are complete, while preserving one active execution per Project. A WorkRequest starts only the Project Manager. A Task starts only from its latest accepted handoff.
