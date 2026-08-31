<?php

namespace App\Services;

use App\Models\AgentRun;
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
            throw new UnexpectedValueException('A persisted Project Agent is required for an Agent session.');
        }

        if ($subject instanceof WorkRequest) {
            if ($agent->role !== 'project_manager') {
                throw new UnexpectedValueException('Only the Project Manager Agent may own a WorkRequest session.');
            }

            if ((int) $agent->project_id !== (int) $subject->project_id) {
                throw new UnexpectedValueException('The Project Manager and WorkRequest must belong to the same Project.');
            }

            return AgentSession::query()->createOrFirst([
                'project_agent_id' => $agent->getKey(),
                'task_id' => null,
                'work_request_id' => $subject->getKey(),
            ]);
        }

        if (! in_array($agent->role, ['coder', 'quality_assurance_specialist'], true)) {
            throw new UnexpectedValueException('Only Coder or Quality Assurance Specialist Agents may own a Task session.');
        }

        $subject->loadMissing('workRequest');

        if ((int) $agent->project_id !== (int) $subject->workRequest->project_id) {
            throw new UnexpectedValueException('The Agent and Task must belong to the same Project.');
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
     * @param  array{mode: string, input: string, sources: array<int, array<string, string>>}  $context
     */
    public function startRun(
        AgentSession $session,
        string $purpose,
        array $context,
    ): AgentRun {
        $purpose = trim($purpose);

        if ($purpose === '') {
            throw new UnexpectedValueException('An Agent run purpose is required.');
        }

        if (
            ! isset($context['mode'], $context['input'], $context['sources'])
            || ! in_array($context['mode'], ['initial', 'delta'], true)
            || ! is_string($context['input'])
            || trim($context['input']) === ''
            || ! is_array($context['sources'])
        ) {
            throw new UnexpectedValueException('Agent run context must contain a valid mode, submitted input, and context sources.');
        }

        return DB::transaction(function () use ($session, $purpose, $context): AgentRun {
            $lockedSession = AgentSession::query()
                ->lockForUpdate()
                ->findOrFail($session->getKey());

            $attempt = ((int) ($lockedSession->runs()->max('attempt') ?? 0)) + 1;

            return $lockedSession->runs()->create([
                'purpose' => $purpose,
                'status' => 'running',
                'attempt' => $attempt,
                'context_mode' => $context['mode'],
                'submitted_input' => $context['input'],
                'context_sources' => array_values($context['sources']),
                'output_summary' => null,
                'raw_output_reference' => null,
                'exit_code' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]);
        }, attempts: 3);
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
            throw new UnexpectedValueException('The provider session identifier exceeds the supported length.');
        }

        DB::transaction(function () use ($session, $providerSessionId): void {
            $lockedSession = AgentSession::query()
                ->lockForUpdate()
                ->findOrFail($session->getKey());

            if ($lockedSession->provider_session_id === null) {
                $lockedSession->update([
                    'provider_session_id' => $providerSessionId,
                ]);

                return;
            }

            if ($lockedSession->provider_session_id !== $providerSessionId) {
                throw new UnexpectedValueException(
                    'The provider returned a different session identifier while resuming the logical Agent session.',
                );
            }
        }, attempts: 3);

        $session->refresh();
    }

    /**
     * Mark one running invocation successful with a concise durable summary.
     */
    public function completeRun(
        AgentRun $run,
        string $summary,
        ?int $exitCode = null,
        ?string $rawOutputReference = null,
    ): void {
        $summary = trim($summary);

        if ($summary === '') {
            $summary = 'Agent execution completed successfully.';
        }

        AgentRun::query()
            ->whereKey($run->getKey())
            ->where('status', 'running')
            ->update([
                'status' => 'succeeded',
                'output_summary' => Str::limit($summary, 2000, ''),
                'raw_output_reference' => $rawOutputReference,
                'exit_code' => $exitCode,
                'finished_at' => now(),
            ]);

        $run->refresh();
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
     * Produce a concise high-level summary for future non-structured Coder or QA results without retaining their transcript as context.
     */
    public function summarizeOutput(string $output): string
    {
        $summary = preg_replace('/\s+/u', ' ', trim($output));

        return Str::limit($summary ?? trim($output), 1000, '');
    }
}
