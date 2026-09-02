---
paths:
    - 'app/Services/TaskCommitIntegrator.php,app/Services/RepairCycleGuard.php,app/Services/CandidateAcceptanceGate.php,app/Services/ProjectVerificationService.php,app/Jobs/ProcessAgentExecution.php'
---

# Services Jobs

## Authoritative CI-gated Task completion and bounded QA/Coder repair loop

A Task only reaches `completed` through `TaskCommitIntegrator`. Finalization requires `CandidateAcceptanceGate::hasCurrentApproval()`, exact current `candidate_tree_sha` identity, required vault documentation evidence, and authoritative Project verification for the operator-approved `ci` profile.

`ProjectVerificationService` is the single CI execution authority. Finalization must never independently execute `composer ci:check` or another hard-coded CI Process command. A reusable successful or failed `ProjectVerificationRun` must belong to the exact Project, Task, `ci` profile, `task_candidate` target, and current `candidate_tree_sha`. When no reusable decisive attempt exists, finalization may request the configured `ci` profile only through `ProjectVerificationService`.

`passed` verification may authorize finalization after the current worktree and final commit tree are revalidated. `failed` verification creates a durable bounded `ci_failed` repair handoff. `stale_candidate`, `environment_unavailable`, `timed_out`, running, or otherwise inconclusive verification must not authorize finalization and must not be mislabeled as a Coder code defect.

`ProjectVerificationRun` is intermediate durable evidence only. It does not independently mutate Task workflow state.

`RepairCycleGuard` bounds each operator-approved autonomous repair episode. The active count includes QA `changes_requested` reviews and authoritative `ci_failed` handoffs created after the Task current repair-cycle boundaries. Historical reviews and handoffs remain immutable and queryable. An explicit operator Retry advances the boundaries and starts a fresh `0 / max_repair_cycles` budget while preserving the durable handoff required for redispatch.

The current repair event is persisted before the limit is evaluated. Reaching the configured limit therefore makes that event durably terminal. Do not reset repair boundaries during automatic QA to Coder or CI-failure handoffs.

Do not revert `hasCurrentApproval()` to the old "no changes_requested ever recorded" semantics. The latest review for the current immutable candidate tree is authoritative.
