---
paths:
    - 'app/Jobs/*.php,app/Services/AgentExecutionRunner.php,app/Console/Commands/DispatchWorkflow.php'
---

# Console Commands

## Agent-owned workflow: coarse states, minimal contract, Laravel owns infra only

This app was refactored (docs/09-agent-owned-workflow-refactor.md) so Laravel is only a dispatch/execution/persistence/audit layer. Do NOT reintroduce Laravel-side validation of how work is planned, implemented, tested, reviewed, or committed — that broke real Agent runs before (see docs/09 "Exhibit A").

Task/WorkRequest.status is coarse: pending → running → waiting → completed/failed/cancelled. Task.blocked_reason and WorkRequest.failure_reason hold the terminal 'failed' reason (different column names — see ProcessAgentExecution::failureReasonColumn()). Manual Retry always re-enters 'pending' and clears last_handoff — there is no fine-grained recovery-state mapping anymore.

ProcessAgentExecution is the single Job for all Agent work. A configurable Foreman owns WorkRequest analysis, Task decomposition, delegation, review, repair, and recovery; persistent specialist Agents and ephemeral provider subagents are delegates rather than Laravel stages. AgentExecutionRunner validates structure and integrity only, snapshots prompt/configuration, and persists run evidence.

AISF independently owns worktrees, exact-SHA CI, pull requests, review evidence, merge policy, and merge gates. A code-producing ProjectAgent cannot approve its own candidate. Recoverable engineering failures become bounded fresh Foreman turns with durable evidence; queue retries are for infrastructure failures only.

DispatchWorkflow (`workflow:dispatch`, scheduled every minute) has only 2 rules: prefer the Project's pending/waiting WorkRequest, else the lowest-position pending/waiting Task whose dependency is completed; skip when the Project has a running subject. A fresh Task defaults to the Foreman; an explicit handoff or assigned ProjectAgent may select any enabled configured role.
