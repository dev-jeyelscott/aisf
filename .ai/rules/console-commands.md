---
paths:
  - 'app/Jobs/*.php,app/Services/AgentExecutionRunner.php,app/Console/Commands/DispatchWorkflow.php'
---

# Console Commands

## Agent-owned workflow: coarse states, minimal contract, Laravel owns infra only
This app was refactored (docs/09-agent-owned-workflow-refactor.md) so Laravel is only a dispatch/execution/persistence/audit layer. Do NOT reintroduce Laravel-side validation of how work is planned, implemented, tested, reviewed, or committed — that broke real Agent runs before (see docs/09 "Exhibit A").

Task/WorkRequest.status is coarse: pending → running → waiting → completed/failed/cancelled. Task.blocked_reason and WorkRequest.failure_reason hold the terminal 'failed' reason (different column names — see ProcessAgentExecution::failureReasonColumn()). Manual Retry always re-enters 'pending' and clears last_handoff — there is no fine-grained recovery-state mapping anymore.

ProcessAgentExecution is the single Job for all Agent work (PM planning, Coder, QA) — do not add per-role Jobs back. AgentExecutionRunner enforces only {status, summary, handoff?, commit_sha?, tasks?, already_implemented?} via JSON schema; role-specific behavior (acceptance criteria, browser tests, commit timing, QA gating) lives in each ProjectAgent's workflow_instructions, not in PHP.

DispatchWorkflow (`workflow:dispatch`, scheduled every minute) has only 2 rules: prefer the Project's own pending/waiting WorkRequest, else the lowest-position pending/waiting Task whose dependency is 'completed'; skip entirely if anything in the Project is already 'running'. Task.last_handoff['to_role'] (coder|quality_assurance_specialist) determines which ProjectAgent role AgentExecutionRunner invokes next — default 'coder' when null/fresh.
