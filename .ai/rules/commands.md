---
paths:
  - 'app/Console/Commands/*.php'
---

# Commands

## workflow:dispatch is the only scheduler entrypoint — never call Agents from it
app/Console/Commands/DispatchWorkflow.php (`workflow:dispatch`, scheduled every minute in routes/console.php via Schedule::command()->everyMinute()->withoutOverlapping()) is the single dispatcher for the whole PM→Coder→QA→fix→commit workflow. It only does short DB eligibility reads and transactional claims (conditional `UPDATE ... WHERE status = 'x'`, then dispatch only if the update affected a row) — it must never construct/call ProjectManagerPlanner, TaskCoder, TaskQaReviewer, or any Agent harness directly. All actual Agent execution happens inside the four ProcessX Jobs after they're dispatched.

Eligibility order per enabled Project, matching docs/08-scheduler-queue-retries-and-observability.md: (1) submitted WorkRequest with no other WorkRequest 'processing' → ProcessWorkRequest; (2) changes_required Task with no Task 'coding' in the Project → ProcessTaskCoding; (3) approved Task (approved_at set) with no Task committing/integrating → ProcessTaskCommit; (4) ready_for_qa Task with no Task qa_reviewing → ProcessTaskQaReview; (5) only if no Coder Task became active in (2), the lowest-position queued Task whose dependsOn is null or done → ProcessTaskCoding. "One active Coder Task per Project" is enforced both by this ordering and by ProcessTaskCoding::uniqueId() returning the project id.
