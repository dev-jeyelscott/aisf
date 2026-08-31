---
paths:
  - 'app/Jobs/Process*.php'
---

# Jobs

## Superseded — see console-commands.md

The `blocked` + `blocked_from_status` fine-grained recovery-state design documented here previously was replaced by the Agent-owned workflow refactor (docs/09-agent-owned-workflow-refactor.md). There is now one Job (`ProcessAgentExecution`), status is the coarse `pending|running|waiting|completed|failed|cancelled` vocabulary, and manual Retry always re-enters `pending`. See the rule in `.ai/rules/console-commands.md` ("Agent-owned workflow: coarse states, minimal contract, Laravel owns infra only") for the current design — do not follow the old `blocked_from_status` pattern.
