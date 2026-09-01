---
paths:
    - 'app/Services/{TaskCandidateFingerprint,CandidateAcceptanceGate,TaskCommitIntegrator,TaskWorkflowService}.php,app/Mcp/Tools/{SaveTaskResult,SaveQaReview,FinalizeTask}.php'
---

# Tools

## QA approval is bound to an immutable Git tree

`candidate_tree_sha` is computed from a temporary Git index that includes all worktree changes without touching the real index or creating a commit. Reviews and finalization must match that exact tree and `candidate_created_by_run_id`; legacy `candidate_sha` is never workflow authority.
