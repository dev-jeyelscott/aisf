import {
    Ban,
    CheckCircle2,
    CircleDashed,
    Clock3,
    LoaderCircle,
    XCircle,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';

export type WorkflowStatus =
    | 'pending'
    | 'running'
    | 'waiting'
    | 'completed'
    | 'failed'
    | 'cancelled';

export type ContextSource = {
    type: string;
    label: string;
};

export type AgentRun = {
    id: number;
    attempt: number;
    purpose: string;
    status: string;
    reconciliation_status: 'satisfied' | 'recoverable' | 'terminal' | null;
    failure_class:
        | 'protocol_recoverable'
        | 'infrastructure_recoverable'
        | 'engineering_repair'
        | 'terminal_blocked'
        | null;
    context_mode: 'initial' | 'delta';
    submitted_input: string;
    context_sources: ContextSource[];
    output_summary: string | null;
    exit_code: number | null;
    harness: string | null;
    model: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type AgentSession = {
    id: number;
    has_provider_continuity: boolean;
    agent: {
        id: number;
        name: string;
        role: string;
    };
    runs: AgentRun[];
};

export type LatestHandoff = {
    id?: number | null;
    from_role?: string | null;
    to_role?: string | null;
    reason?: string | null;
    note?: string | null;
} | null;

export type HandoffRecord = {
    id: number;
    from_role: string | null;
    to_role: string | null;
    reason: string;
    dispatched_at: string | null;
};

export type CandidateReview = {
    candidate_tree_sha: string | null;
    status: string;
    summary: string | null;
    findings: unknown;
    created_at: string | null;
};

export type Task = {
    id: number;
    depends_on_task_id: number | null;
    position: number;
    title: string;
    objective: string;
    implementation_spec: string;
    status: WorkflowStatus;
    outcome: 'implemented' | 'no_change' | 'blocked' | null;
    protocol_recovery_count: number;
    candidate_tree_sha: string | null;
    candidate_kind: 'changes' | 'no_change' | null;
    branch_name: string | null;
    worktree_path: string | null;
    blocked_reason: string | null;
    last_handoff: LatestHandoff;
    commit_sha: string | null;
    pull_request_url: string | null;
    changed_files: string[];
    candidate_reviews: CandidateReview[];
    agent_sessions: AgentSession[];
    handoffs: HandoffRecord[];
    repair_cycle_count: number;
    repair_cycle_limit: number;
};

export type WorkRequest = {
    id: number;
    prompt: string;
    status: WorkflowStatus;
    outcome: 'implemented' | 'already_implemented' | 'blocked' | null;
    protocol_recovery_count: number;
    summary: string | null;
    evidence: string[] | null;
    failure_reason: string | null;
    last_handoff: LatestHandoff;
    source_type: 'manual' | 'github' | 'notion';
    source_url: string | null;
    agent_sessions: AgentSession[];
    tasks: Task[];
};

/**
 * Convert persisted enum-like values into readable labels.
 */
export function humanize(value: string): string {
    return value.replace(/_/g, ' ');
}

/**
 * Format a durable execution timestamp using the operator's browser locale.
 */
export function formatTimestamp(value: string | null): string {
    if (!value) {
        return 'In progress';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

/**
 * Return a compact Git identifier while preserving its full value elsewhere.
 */
export function shortSha(value: string): string {
    return value.slice(0, 8);
}

/**
 * Determine whether a persisted string is a supported workflow state.
 */
export function isWorkflowStatus(value: string): value is WorkflowStatus {
    return [
        'pending',
        'running',
        'waiting',
        'completed',
        'failed',
        'cancelled',
    ].includes(value as WorkflowStatus);
}

/**
 * Serialize persisted QA findings without assuming a narrower storage shape.
 */
export function serializeFindings(findings: unknown): string {
    if (findings === null || findings === undefined) {
        return 'No findings recorded.';
    }

    if (typeof findings === 'string') {
        return findings;
    }

    if (typeof findings === 'number' || typeof findings === 'boolean') {
        return String(findings);
    }

    return JSON.stringify(findings, null, 2) ?? 'No findings recorded.';
}

/**
 * Render the canonical Task or WorkRequest workflow status with text and icon semantics.
 */
export function StatusBadge({ status }: { status: WorkflowStatus }) {
    let Icon = CircleDashed;
    let variant: 'outline' | 'secondary' | 'destructive' = 'outline';
    let className = 'text-muted-foreground';
    let iconClassName = '';

    if (status === 'running') {
        Icon = LoaderCircle;
        className = 'border-primary/30 bg-primary/10 text-primary';
        iconClassName = 'motion-safe:animate-spin motion-reduce:animate-none';
    }

    if (status === 'waiting') {
        Icon = Clock3;
        variant = 'secondary';
        className = 'text-foreground';
    }

    if (status === 'completed') {
        Icon = CheckCircle2;
        variant = 'secondary';
        className = 'text-foreground';
    }

    if (status === 'failed') {
        Icon = XCircle;
        variant = 'destructive';
        className = '';
    }

    if (status === 'cancelled') {
        Icon = Ban;
        className = 'text-muted-foreground opacity-80';
    }

    return (
        <Badge variant={variant} className={className}>
            <Icon className={iconClassName} aria-hidden="true" />
            <span className="capitalize">{humanize(status)}</span>
        </Badge>
    );
}

/**
 * Render an AgentRun status using canonical workflow semantics when possible.
 */
export function RunStatusBadge({ status }: { status: string }) {
    const normalizedStatus =
        status === 'succeeded'
            ? 'completed'
            : isWorkflowStatus(status)
              ? status
              : null;

    if (normalizedStatus) {
        return <StatusBadge status={normalizedStatus} />;
    }

    return <Badge variant="outline">{humanize(status)}</Badge>;
}

/**
 * Render a QA review state without introducing unsupported workflow states.
 */
export function ReviewStatusBadge({ status }: { status: string }) {
    if (status === 'approved') {
        return (
            <Badge variant="secondary">
                <CheckCircle2 aria-hidden="true" />
                <span className="capitalize">{humanize(status)}</span>
            </Badge>
        );
    }

    if (
        status === 'rejected' ||
        status === 'changes_requested' ||
        status === 'failed'
    ) {
        return (
            <Badge variant="destructive">
                <XCircle aria-hidden="true" />
                <span className="capitalize">{humanize(status)}</span>
            </Badge>
        );
    }

    return <Badge variant="outline">{humanize(status)}</Badge>;
}
