<?php

namespace App\Services;

final readonly class AgentTurnReconciliation
{
    public function __construct(
        public string $classification,
        public ?string $failureClass = null,
        public bool $retryInfrastructure = false,
    ) {}
}
