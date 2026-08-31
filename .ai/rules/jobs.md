---
paths:
    - 'app/Jobs/Process*.php'
---

# Jobs

## Superseded — see console-commands.md

The fine-grained recovery-state design was replaced by the Foreman-driven execution model. There is one Job (`ProcessAgentExecution`), status is the coarse `pending|running|waiting|completed|failed|cancelled` vocabulary, and manual Retry always re-enters `pending`. See `.ai/rules/console-commands.md`; do not add role-specific Jobs or SDLC-state transitions.
