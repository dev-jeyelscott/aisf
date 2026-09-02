---
paths:
    - app/Services/ProjectVerificationService.php
    - app/Mcp/Tools/RunProjectVerification.php
    - app/Models/ProjectVerificationRun.php
    - app/Http/Controllers/ProjectVerificationController.php
    - app/Http/Requests/UpdateProjectVerificationRequest.php
---

# Host-Controlled Project Verification

Agents never receive Docker daemon access through this MCP contract. Project verification is requested through the active AgentRun and execution token, while Laravel owns authorization, process execution, durable evidence, and infrastructure policy.

When `config('aisf.trusted_local_execution')` is enabled for the AISF worker deployment, the provider CLI subprocess itself may use the worker user's own pre-existing Docker group membership directly, the same as a developer's terminal session — this is a deliberately trusted local-execution boundary, not a relaxation of the MCP tool contract below. The Docker-sandboxed verifier-container bridge described in this document remains fully available and unchanged in that mode as an optional, operator-approved path to durable, idempotency-keyed verification evidence; it is simply no longer the only way Docker-dependent checks can run.

Agent input may select only an operator-approved verification profile and idempotency key. Never add raw command, Docker option, service, container, environment, volume, mount, executable argument, or shell input to the MCP contract.

Docker Compose definitions used by the verification bridge must be AISF/operator-owned and outside every Agent-managed Project repository. Never execute a Project-controlled compose.yaml as host infrastructure policy.

Docker verification must use a dedicated verifier service that is non-privileged, has no host bind mounts, no Docker socket, no host network/PID/IPC namespace, no host devices, and no added capabilities.

The exact Project checkout or durable Task candidate is staged into the verifier container. QA verification must match the Task candidate_tree_sha before and after execution.

Native verification executes Project-controlled code as the AISF worker user and is therefore disabled by default. Enable it only when the complete Project repository is trusted. `allow_trusted_native_verification` governs only this service's non-Docker native driver; `trusted_local_execution` (see above) is a separate, broader flag governing whether the Agent's own provider subprocess may use host Docker/tooling directly.

ProjectVerificationRun is intermediate durable evidence. It must never directly hand off, approve, reject, finalize, block, or otherwise mutate Task workflow state.

Environment-only verification failures remain blocked workflow evidence for QA and must not produce a false Coder repair cycle.
