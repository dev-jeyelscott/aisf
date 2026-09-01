<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\WorkRequestIngestion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use UnexpectedValueException;

/**
 * Ingest a GitHub Issue labeled ready-for-AI into the same durable WorkRequest contract a manual
 * submission produces. Verified by an HMAC signature over the raw payload using the Project's own
 * configured webhook secret, so only that Project's GitHub repository can trigger it.
 */
class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, Project $project, WorkRequestIngestion $ingestion): Response
    {
        if (! $this->hasValidSignature($request, $project)) {
            return response('Invalid signature.', 401);
        }

        if ($request->header('X-GitHub-Event') !== 'issues') {
            return response('Ignored: not an issues event.', 200);
        }

        $payload = $request->json()->all();
        $labels = collect((array) ($payload['issue']['labels'] ?? []))->pluck('name');
        $readyLabel = (string) $project->github_ready_label;

        if (! $labels->contains($readyLabel)) {
            return response('Ignored: issue is not labeled ready for AI.', 200);
        }

        $issue = $payload['issue'] ?? null;
        $repository = $payload['repository']['full_name'] ?? null;

        if (! is_array($issue) || ! is_string($repository)) {
            return response('Ignored: malformed issue payload.', 200);
        }

        try {
            $ingestion->ingest(
                $project,
                'github',
                trim(($issue['title'] ?? '').\PHP_EOL.\PHP_EOL.($issue['body'] ?? '')),
                "{$repository}#{$issue['number']}",
                $issue['html_url'] ?? null,
                ['repository' => $repository, 'issue_number' => $issue['number'] ?? null],
            );
        } catch (UnexpectedValueException $exception) {
            return response($exception->getMessage(), 422);
        }

        return response('Accepted.', 202);
    }

    private function hasValidSignature(Request $request, Project $project): bool
    {
        $secret = (string) $project->github_webhook_secret;
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
