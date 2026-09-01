---
paths:
    - 'app/Services/TaskCommitIntegrator.php,app/Services/RepairCycleGuard.php,app/Services/CandidateAcceptanceGate.php,app/Jobs/ProcessAgentExecution.php'
---

# Services Jobs

## CI-gated Task completion and bounded QA/Coder repair loop

A Task only reaches `completed` through TaskCommitIntegrator: it requires CandidateAcceptanceGate::hasCurrentApproval() (the _latest_ CandidateReview for the Task's current candidate_sha must be 'approved' — not "no changes_requested ever"), then verifies the commit and runs the Project's `composer ci:check` before opening a PR. A failing CI check creates a durable `ci_failed` handoff back to the Coder instead of a red PR. RepairCycleGuard bounds the loop: `candidateReviews(changes_requested count) + handoffs(reason=ci_failed count) >= config('aisf.max_repair_cycles')` durably fails the Task with a blocked_reason instead of looping forever — an operator must Retry it. Do not revert hasCurrentApproval to the old "no changes_requested ever recorded" semantics; it made a Task permanently unapprovable after a single repair cycle.
