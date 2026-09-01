---
paths:
    - 'app/Services/WorkflowDispatcher.php,app/Jobs/DispatchWorkflowForProject.php,app/Console/Commands/DispatchWorkflow.php'
---

# Jobs Console Commands

## Handoffs dispatch immediately; workflow:dispatch is the recovery net

WorkflowDispatcher::dispatchForProject() holds the one-active-execution-per-Project claim/dispatch logic, shared by DispatchWorkflow (the every-minute reconciliation sweep) and by DispatchWorkflowForProject (queued with a 2s delay at the end of every ProcessAgentExecution::handle(), so a happy-path handoff advances almost immediately instead of waiting for the next scheduler tick). The delay exists because ShouldBeUnique's per-project lock is only released after handle() fully returns — dispatching sooner risks the attempt being silently dropped; if that happens the scheduler still reconciles it within a minute. Don't dispatch ProcessAgentExecution synchronously from inside its own still-running handle().
