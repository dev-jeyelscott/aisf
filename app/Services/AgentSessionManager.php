<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\AgentSession;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class AgentSessionManager
{
    /**
     * Find or race-safely create the one logical session for an Agent and Task or WorkRequest subject.
     */
    public function forSubject(
        ProjectAgent $agent,
        Task|WorkRequest $subject,
    ): AgentSession {
        if (! $agent->exists) {
            throw new UnexpectedValueException(
                'A persisted Project Agent is required for an Agent session.',
            );
        }

        if ($subject instanceof WorkRequest) {
            if ((int) $agent->project_id !== (int) $subject->project_id) {
                throw new UnexpectedValueException(
                    'The Agent and WorkRequest must belong to the same Project.',
                );
            }

            return AgentSession::query()->createOrFirst([
                'project_agent_id' => $agent->getKey(),
                'task_id' => null,
                'work_request_id' => $subject->getKey(),
            ]);
        }

        $subject->loadMissing('workRequest');

        if (
            (int) $agent->project_id
            !== (int) $subject->workRequest->project_id
        ) {
            throw new UnexpectedValueException(
                'The Agent and Task must belong to the same Project.',
            );
        }

        return AgentSession::query()->createOrFirst([
            'project_agent_id' => $agent->getKey(),
            'task_id' => $subject->getKey(),
            'work_request_id' => null,
        ]);
    }

    /**
     * Allocate the next monotonically increasing run attempt while holding the logical session row lock.
     *
     * @param  array<string, mixed>  $context
     */
    public function startRun(
        AgentSession $session,
        string $purpose,
        array $context,
        ?AgentRun $parent = null,
    ): AgentRun {
        $purpose = trim($purpose);

        if ($purpose === '') {
            throw new UnexpectedValueException(
                'An Agent run purpose is required.',
            );
        }

        $mode = $context['mode'] ?? null;
        $input = $context['input'] ?? null;
        $sources = $context['sources'] ?? null;
        $agentSnapshot = $context['agent_snapshot'] ?? null;
        $promptSnapshot = $context['prompt_snapshot'] ?? null;
        $role = $context['role'] ?? null;
        $executionToken = $context['execution_token'] ?? Str::random(64);

        if (
            ! is_string($mode)
            || ! in_array($mode, ['initial', 'delta'], true)
            || ! is_string($input)
            || trim($input) === ''
            || ! is_array($sources)
            || ! is_array($agentSnapshot)
            || ! is_array($promptSnapshot)
            || ! is_string($executionToken)
            || trim($executionToken) === ''
        ) {
            throw new UnexpectedValueException(
                'Agent run context must contain a valid mode, submitted input, and context sources.',
            );
        }

        $sessionId = (int) $session->getKey();

        return DB::transaction(
            function () use (
                $sessionId,
                $purpose,
                $mode,
                $input,
                $sources,
                $agentSnapshot,
                $promptSnapshot,
                $role,
                $executionToken,
                $parent,
            ): AgentRun {
                $lockedSession = AgentSession::query()
                    ->lockForUpdate()
                    ->findOrFail($sessionId);

                $attempt = (
                    (int) ($lockedSession->runs()->max('attempt') ?? 0)
                ) + 1;

                return $lockedSession->runs()->create([
                    'purpose' => $purpose,
                    'role' => $role,
                    'execution_token' => $executionToken,
                    'status' => 'running',
                    'attempt' => $attempt,
                    'parent_agent_run_id' => $parent?->getKey(),
                    'context_mode' => $mode,
                    'submitted_input' => $input,
                    'context_sources' => array_values($sources),
                    'agent_snapshot' => $agentSnapshot,
                    'prompt_snapshot' => $promptSnapshot,
                    'output_summary' => null,
                    'raw_output_reference' => null,
                    'exit_code' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                ]);
            },
            attempts: 3,
        );
    }

    /**
     * Capture a provider session identifier once and reject unexpected provider identity changes during resume.
     */
    public function captureProviderSessionId(
        AgentSession $session,
        ?string $providerSessionId,
    ): void {
        if (! filled($providerSessionId)) {
            return;
        }

        $providerSessionId = trim((string) $providerSessionId);

        if (Str::length($providerSessionId) > 255) {
            throw new UnexpectedValueException(
                'The provider session identifier exceeds the supported length.',
            );
        }

        $sessionId = (int) $session->getKey();

        DB::transaction(
            function () use ($sessionId, $providerSessionId): void {
                $lockedSession = AgentSession::query()
                    ->lockForUpdate()
                    ->findOrFail($sessionId);

                if ($lockedSession->provider_session_id === null) {
                    $lockedSession->update([
                        'provider_session_id' => $providerSessionId,
                    ]);

                    return;
                }

                if (
                    $lockedSession->provider_session_id
                    !== $providerSessionId
                ) {
                    throw new UnexpectedValueException(
                        'The provider returned a different session identifier while resuming the logical Agent session.',
                    );
                }
            },
            attempts: 3,
        );

        $session->refresh();
    }

    /**
     * Mark one running invocation successful and atomically record that its workflow outcome was persisted.
     *
     * @param  array<string, mixed>  $executionMetadata
     */
    public function completeRun(
        AgentRun $run,
        string $summary,
        ?int $exitCode = null,
        ?string $rawOutputReference = null,
        array $executionMetadata = [],
    ): void {
        $summary = trim($summary);

        if ($summary === '') {
            $summary = 'Agent execution completed successfully.';
        }

        DB::transaction(
            function () use ($run, $summary, $exitCode, $rawOutputReference, $executionMetadata): void {
                $lockedRun = AgentRun::query()
                    ->lockForUpdate()
                    ->findOrFail($run->getKey());

                if ($lockedRun->status !== 'running') {
                    return;
                }

                $lockedRun->update([
                    'status' => 'succeeded',
                    'output_summary' => Str::limit($summary, 2000, ''),
                    'raw_output_reference' => $rawOutputReference,
                    'execution_metadata' => array_merge(
                        $lockedRun->execution_metadata ?? [],
                        $executionMetadata,
                    ),
                    'exit_code' => $exitCode,
                    'finished_at' => now(),
                ]);

                $lockedRun->actions()->create([
                    'action' => AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
                    'resource_type' => AgentRunAction::RESOURCE_AGENT_RUN,
                    'resource_id' => $lockedRun->id,
                ]);
            },
            attempts: 3,
        );

        $run->refresh();
    }

    /**
     * Persist an ephemeral subagent reported by a parent execution without treating provider state as durable.
     *
     * @param  array<string, mixed>  $delegation
     */
    public function recordDelegation(AgentRun $parent, array $delegation): AgentRun
    {
        $purpose = trim((string) ($delegation['purpose'] ?? 'Delegated engineering work'));
        $status = in_array($delegation['status'] ?? null, ['succeeded', 'failed', 'running'], true)
            ? $delegation['status']
            : 'succeeded';

        $attempt = ((int) $parent->agentSession->runs()->max('attempt')) + 1;

        return $parent->agentSession->runs()->create([
            'parent_agent_run_id' => $parent->id,
            'purpose' => $purpose,
            'role' => $delegation['role'] ?? 'ephemeral_subagent',
            'status' => $status,
            'attempt' => $attempt,
            'context_mode' => 'initial',
            'submitted_input' => (string) ($delegation['instructions'] ?? $purpose),
            'context_sources' => [
                [
                    'type' => 'parent_agent_run',
                    'label' => 'Parent Foreman execution',
                ],
            ],
            'agent_snapshot' => [
                'kind' => 'ephemeral',
                'harness' => $delegation['harness'] ?? null,
                'model' => $delegation['model'] ?? null,
            ],
            'prompt_snapshot' => [
                'purpose' => $purpose,
                'instructions' => $delegation['instructions'] ?? null,
            ],
            'output_summary' => $delegation['evidence'] ?? null,
            'raw_output_reference' => null,
            'execution_metadata' => [
                'delegation' => $delegation,
            ],
            'artifacts' => $delegation['artifacts'] ?? [],
            'exit_code' => null,
            'started_at' => now(),
            'finished_at' => $status === 'running' ? null : now(),
        ]);
    }

    /**
     * Mark one running invocation failed without persisting provider transcripts or sensitive process output.
     */
    public function failRun(
        AgentRun $run,
        Throwable $exception,
        ?int $exitCode = null,
        ?string $rawOutputReference = null,
    ): void {
        $summary = trim($exception->getMessage());

        if ($summary === '') {
            $summary = 'Agent execution failed.';
        }

        AgentRun::query()
            ->whereKey($run->getKey())
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'output_summary' => Str::limit($summary, 2000, ''),
                'raw_output_reference' => $rawOutputReference,
                'exit_code' => $exitCode,
                'finished_at' => now(),
            ]);

        $run->refresh();
    }

    /**
     * Produce a concise high-level summary without retaining a provider transcript as context.
     */
    public function summarizeOutput(string $output): string
    {
        $summary = preg_replace('/\s+/u', ' ', trim($output));

        return Str::limit(
            $summary ?? trim($output),
            1000,
            '',
        );
    }
}
