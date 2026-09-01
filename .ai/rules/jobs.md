---
paths:
    - 'app/Jobs/Process*.php'
---

# Jobs

## Durable handoffs — see console-commands.md

There is one Job (`ProcessAgentExecution`), status is the coarse `pending|running|waiting|completed|failed|cancelled` vocabulary, and manual Retry always re-enters `pending`. Project Agents drive PM → Coder → QA transitions through durable handoffs. Do not add role-specific Jobs or SDLC-state transitions.
