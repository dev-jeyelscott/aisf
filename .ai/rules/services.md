---
paths:
    - 'app/Services/WorkRequestIngestion.php,app/Http/Controllers/GithubWebhookController.php,app/Console/Commands/SyncNotionTasks.php,app/Services/NotionClient.php'
---

# Services

## External sources (GitHub/Notion) upsert into the same WorkRequest contract

WorkRequestIngestion::ingest() is the single source-agnostic entry point manual submission, the GitHub webhook, and notion:sync all use. It upserts on the (project_id, source_type, source_external_id) unique constraint — a duplicate webhook delivery or poll simply returns the existing WorkRequest, never a duplicate. GithubWebhookController verifies X-Hub-Signature-256 with the Project's own github_webhook_secret (per-Project, not global) before trusting a payload, and only acts on an issue carrying the Project's configured github_ready_label. NotionClient is a thin, mockable adapter over the Notion API so SyncNotionTasks stays testable; a per-Project sync failure is caught and logged, never allowed to interrupt another Project's sync or any active Agent execution. Do not let an external source pick a Task/WorkRequest status directly or bypass QA/CI — ingestion only ever creates a WorkRequest, identical to a manual submission.
