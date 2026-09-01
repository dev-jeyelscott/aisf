---
paths:
    - app/Http/Controllers/TaskController.php
---

# Controllers

## Preserve durable handoffs when retrying Tasks

Task retries must preserve an existing durable handoff so the Task remains eligible for dispatch. Queue ProcessAgentExecution immediately when that handoff exists; terminal failures without a handoff remain non-dispatchable.
