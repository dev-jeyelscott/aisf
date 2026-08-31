<?php

namespace App\Services;

final readonly class AgentHarnessResult
{
    /**
     * Describe one completed CLI harness process without persisting provider transcripts.
     */
    public function __construct(
        public bool $successful,
        public ?string $output,
        public ?string $providerSessionId,
        public ?int $exitCode,
        public ?string $failureMessage = null,
    ) {}
}
