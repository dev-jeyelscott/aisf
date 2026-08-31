---
paths:
    - 'app/Console/Commands/*.php'
---

# Commands

## workflow:dispatch is the only scheduler entrypoint — never call Agents from it

app/Console/Commands/DispatchWorkflow.php (`workflow:dispatch`, scheduled every minute in routes/console.php via `Schedule::command()->everyMinute()->withoutOverlapping()`) dispatches durable WorkRequests and Tasks only. It makes short eligibility reads and atomic status claims; it must never call an Agent harness. One active Project execution is an infrastructure constraint, not an engineering workflow stage.

Prefer an eligible pending/waiting WorkRequest, then the lowest-position eligible Task whose dependency is completed. The selected subject runs through `ProcessAgentExecution`; roles, delegation, review, repair, and recovery are Foreman decisions recorded in durable evidence.
