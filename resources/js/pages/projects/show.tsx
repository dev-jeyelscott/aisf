import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    Ban,
    CheckCircle2,
    CircleDashed,
    Clock3,
    ExternalLink,
    FolderGit2,
    GitBranch,
    GitCommitHorizontal,
    LoaderCircle,
    Pause,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { edit, index } from '@/routes/projects';
import { index as agents } from '@/routes/projects/agents';
import { retry as retryTask, run as runTask } from '@/routes/projects/tasks';
import { store as storeWorkRequest } from '@/routes/projects/work-requests';

type WorkflowStatus =
    | 'pending'
    | 'running'
    | 'waiting'
    | 'completed'
    | 'failed'
    | 'cancelled';

type Project = {
    id: number;
    title: string;
    description: string | null;
    path: string;
    enabled: boolean;
    merge_policy: 'human' | 'automatic';
};

type RepositoryStatus = {
    branch: string | null;
    headSha: string;
    isClean: boolean;
};

type ContextSource = {
    type: string;
    label: string;
};

type AgentRun = {
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

type AgentSession = {
    id: number;
    has_provider_continuity: boolean;
    agent: {
        id: number;
        name: string;
        role: string;
    };
    runs: AgentRun[];
};

type Handoff = {
    to_role?: string | null;
    note?: string | null;
} | null;

type HandoffRecord = {
    id: number;
    from_role: string | null;
    to_role: string | null;
    reason: string;
    dispatched_at: string | null;
};

type CandidateReview = {
    candidate_tree_sha: string | null;
    status: string;
    summary: string | null;
    findings: unknown;
    created_at: string | null;
};

type Task = {
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
    last_handoff: Handoff;
    commit_sha: string | null;
    pull_request_url: string | null;
    changed_files: string[];
    candidate_reviews: CandidateReview[];
    agent_sessions: AgentSession[];
    handoffs: HandoffRecord[];
    repair_cycle_count: number;
    repair_cycle_limit: number;
};

type WorkRequest = {
    id: number;
    prompt: string;
    status: WorkflowStatus;
    outcome: 'implemented' | 'already_implemented' | 'blocked' | null;
    protocol_recovery_count: number;
    summary: string | null;
    evidence: string[] | null;
    failure_reason: string | null;
    last_handoff: Handoff;
    source_type: 'manual' | 'github' | 'notion';
    source_url: string | null;
    agent_sessions: AgentSession[];
    tasks: Task[];
};

type TaskItem = {
    task: Task;
    request: WorkRequest;
};

/**
 * Convert persisted enum-like strings into readable operator labels.
 */
function humanize(value: string): string {
    return value.replace(/_/g, ' ');
}

/**
 * Format persisted execution timestamps for the operator's browser locale.
 */
function formatTimestamp(value: string | null): string {
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
 * Return a compact SHA while preserving the full value elsewhere.
 */
function shortSha(value: string): string {
    return value.slice(0, 8);
}

/**
 * Generate a deterministic visual monogram from the persisted project title.
 */
function projectMonogram(title: string): string {
    const initials = title
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();

    return initials || 'P';
}

/**
 * Determine whether an arbitrary persisted status is one of the workflow states
 * supported by Tasks and WorkRequests.
 */
function isWorkflowStatus(value: string): value is WorkflowStatus {
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
 * Serialize QA findings without assuming a narrower persistence shape than the
 * backend currently guarantees.
 */
function serializeFindings(findings: unknown): string {
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
 * Render a canonical workflow status using semantic application treatments and
 * an explicit icon so meaning never relies on color alone.
 */
function StatusBadge({ status }: { status: WorkflowStatus }) {
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
 * Render an AgentRun status while reusing canonical Task status treatment when
 * its persisted value maps cleanly to one.
 */
function RunStatusBadge({ status }: { status: string }) {
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
 * Render a compact QA review status without inventing new Task workflow states.
 */
function ReviewStatusBadge({ status }: { status: string }) {
    if (status === 'approved') {
        return (
            <Badge variant="secondary">
                <CheckCircle2 aria-hidden="true" />
                {humanize(status)}
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
                {humanize(status)}
            </Badge>
        );
    }

    return <Badge variant="outline">{humanize(status)}</Badge>;
}

/**
 * Render one label/value pair in inspection metadata grids.
 */
function MetadataField({
    label,
    children,
    mono = false,
}: {
    label: string;
    children: React.ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd
                className={`mt-1 text-sm ${
                    mono ? 'font-mono break-all' : 'break-words'
                }`}
            >
                {children}
            </dd>
        </div>
    );
}

/**
 * Render a consistently styled progressive-disclosure inspection section.
 */
function InspectionSection({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="bg-muted/40 rounded-lg p-4">
            <h3 className="text-sm font-semibold">{title}</h3>
            <div className="mt-3">{children}</div>
        </section>
    );
}

/**
 * Render logical Agent continuity and durable invocation metadata inside
 * WorkRequest or Task inspection surfaces.
 */
function AgentSessionActivity({
    sessions,
    emptyLabel,
}: {
    sessions: AgentSession[];
    emptyLabel: string;
}) {
    if (sessions.length === 0) {
        return <p className="text-muted-foreground text-sm">{emptyLabel}</p>;
    }

    return (
        <div className="grid gap-3">
            {sessions.map((session) => (
                <article
                    key={session.id}
                    className="bg-background/70 rounded-lg p-3"
                >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="text-sm font-medium">
                                {session.agent.name}
                            </p>
                            <p className="text-muted-foreground mt-0.5 text-xs capitalize">
                                {humanize(session.agent.role)} · Logical session
                                #{session.id}
                            </p>
                        </div>

                        <Badge variant="outline">
                            {session.has_provider_continuity
                                ? 'Provider resume available'
                                : 'Logical continuity only'}
                        </Badge>
                    </div>

                    <div className="mt-3 grid gap-3">
                        {session.runs.map((run) => (
                            <div
                                key={run.id}
                                className="border-border/70 rounded-md border p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">
                                            Run {run.attempt}
                                        </span>
                                        <RunStatusBadge status={run.status} />
                                    </div>

                                    <span className="text-muted-foreground text-xs capitalize">
                                        {humanize(run.purpose)}
                                    </span>
                                </div>

                                {run.output_summary && (
                                    <p className="text-muted-foreground mt-3 text-sm">
                                        {run.output_summary}
                                    </p>
                                )}

                                {(run.reconciliation_status ||
                                    run.failure_class) && (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {run.reconciliation_status && (
                                            <Badge variant="outline">
                                                {humanize(
                                                    run.reconciliation_status,
                                                )}
                                            </Badge>
                                        )}
                                        {run.failure_class && (
                                            <Badge variant="outline">
                                                {humanize(run.failure_class)}
                                            </Badge>
                                        )}
                                    </div>
                                )}

                                <dl className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <MetadataField label="Context mode">
                                        <span className="capitalize">
                                            {humanize(run.context_mode)}
                                        </span>
                                    </MetadataField>
                                    <MetadataField label="Started">
                                        {formatTimestamp(run.started_at)}
                                    </MetadataField>
                                    <MetadataField label="Finished">
                                        {formatTimestamp(run.finished_at)}
                                    </MetadataField>
                                    <MetadataField label="Exit code">
                                        {run.exit_code ?? 'Unavailable'}
                                    </MetadataField>
                                    <MetadataField label="Harness">
                                        {run.harness ?? 'Unavailable'}
                                    </MetadataField>
                                    <MetadataField label="Model">
                                        {run.model ?? 'Unavailable'}
                                    </MetadataField>
                                </dl>

                                {run.context_sources.length > 0 && (
                                    <div className="mt-3">
                                        <p className="text-muted-foreground text-xs">
                                            Context sources
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {run.context_sources.map(
                                                (source, index) => (
                                                    <Badge
                                                        key={`${run.id}-context-${index}`}
                                                        variant="outline"
                                                    >
                                                        {source.label}
                                                        <span className="text-muted-foreground">
                                                            ·{' '}
                                                            {humanize(
                                                                source.type,
                                                            )}
                                                        </span>
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}

                                <details className="border-border mt-3 rounded-md border">
                                    <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                        Inspect submitted input
                                    </summary>
                                    <div className="border-border border-t p-3">
                                        <pre className="bg-muted max-h-96 overflow-auto rounded-md p-3 text-xs break-words whitespace-pre-wrap">
                                            {run.submitted_input}
                                        </pre>
                                    </div>
                                </details>
                            </div>
                        ))}
                    </div>
                </article>
            ))}
        </div>
    );
}

/**
 * Submit the existing complete Project update contract while changing only the
 * enabled state so Pause and Resume remain aliases for existing backend
 * behavior rather than a new workflow.
 */
function ProjectEnabledForm({ project }: { project: Project }) {
    return (
        <Form
            {...ProjectController.update.form(project)}
            className="grid justify-items-end gap-1"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="title" value={project.title} />
                    <input
                        type="hidden"
                        name="description"
                        value={project.description ?? ''}
                    />
                    <input type="hidden" name="path" value={project.path} />
                    <input
                        type="hidden"
                        name="enabled"
                        value={project.enabled ? '0' : '1'}
                    />
                    <input
                        type="hidden"
                        name="merge_policy"
                        value={project.merge_policy}
                    />

                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        {processing ? (
                            <Spinner />
                        ) : project.enabled ? (
                            <Pause aria-hidden="true" />
                        ) : (
                            <Play aria-hidden="true" />
                        )}
                        {project.enabled ? 'Pause' : 'Resume'}
                    </Button>

                    <InputError
                        message={
                            errors.path ??
                            errors.enabled ??
                            errors.merge_policy ??
                            errors.title
                        }
                    />
                </>
            )}
        </Form>
    );
}

/**
 * Render compact repository state using only fields currently supplied by
 * RepositoryInspector and ProjectController.
 */
function RepositoryMeta({
    project,
    repositoryStatus,
}: {
    project: Project;
    repositoryStatus: RepositoryStatus | null;
}) {
    return (
        <div className="grid gap-3">
            {!project.enabled ? (
                <div className="bg-muted/60 text-muted-foreground flex items-start gap-2 rounded-lg p-3 text-sm">
                    <Pause
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Repository inspection is paused while this project is
                    disabled.
                </div>
            ) : repositoryStatus ? (
                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline" className="gap-2 px-3 py-1.5">
                        <GitBranch aria-hidden="true" />
                        <span className="text-muted-foreground">Branch</span>
                        <span>
                            {repositoryStatus.branch ?? 'Detached HEAD'}
                        </span>
                    </Badge>

                    <Tooltip>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                className="focus-visible:ring-ring flex items-center gap-2 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                            >
                                <Badge
                                    variant="outline"
                                    className="pointer-events-none gap-2 px-3 py-1.5"
                                >
                                    <GitCommitHorizontal aria-hidden="true" />
                                    <span className="text-muted-foreground">
                                        HEAD
                                    </span>
                                    <span className="font-mono">
                                        {shortSha(repositoryStatus.headSha)}
                                    </span>
                                </Badge>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>
                            <span className="font-mono">
                                {repositoryStatus.headSha}
                            </span>
                        </TooltipContent>
                    </Tooltip>

                    <Badge variant="outline" className="gap-2 px-3 py-1.5">
                        {repositoryStatus.isClean ? (
                            <CheckCircle2 aria-hidden="true" />
                        ) : (
                            <AlertCircle aria-hidden="true" />
                        )}
                        <span className="text-muted-foreground">
                            Working tree
                        </span>
                        <span>
                            {repositoryStatus.isClean ? 'Clean' : 'Dirty'}
                        </span>
                    </Badge>
                </div>
            ) : (
                <div className="bg-muted/60 text-muted-foreground flex items-start gap-2 rounded-lg p-3 text-sm">
                    <AlertCircle
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Repository status is unavailable. Check the configured path
                    and Git working tree.
                </div>
            )}

            <div className="text-muted-foreground flex min-w-0 items-center gap-2 text-xs">
                <FolderGit2 className="size-4 shrink-0" aria-hidden="true" />
                <span className="truncate font-mono" title={project.path}>
                    {project.path}
                </span>
            </div>
        </div>
    );
}

/**
 * Render the dominant project identity, repository context and supported
 * project-level controls.
 */
function ProjectOverview({
    project,
    repositoryStatus,
    onNewWorkRequest,
}: {
    project: Project;
    repositoryStatus: RepositoryStatus | null;
    onNewWorkRequest: () => void;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="gap-5 p-5 md:p-6">
                <div className="flex flex-col justify-between gap-5 xl:flex-row xl:items-start">
                    <div className="flex min-w-0 gap-4">
                        <div
                            className="bg-primary text-primary-foreground flex size-14 shrink-0 items-center justify-center rounded-xl text-xl font-semibold md:size-16 md:text-2xl"
                            aria-hidden="true"
                        >
                            {projectMonogram(project.title)}
                        </div>

                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                                    {project.title}
                                </h1>

                                <Badge
                                    variant={
                                        project.enabled
                                            ? 'outline'
                                            : 'secondary'
                                    }
                                >
                                    {project.enabled ? (
                                        <Activity aria-hidden="true" />
                                    ) : (
                                        <Pause aria-hidden="true" />
                                    )}
                                    {project.enabled ? 'Enabled' : 'Paused'}
                                </Badge>
                            </div>

                            <p className="text-muted-foreground mt-2 max-w-3xl text-sm leading-6 md:text-base">
                                {project.description ||
                                    'No project description provided.'}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-start gap-2 xl:justify-end">
                        <ProjectEnabledForm project={project} />

                        <Button asChild variant="outline">
                            <Link href={edit(project)}>
                                <Pencil aria-hidden="true" />
                                Edit
                            </Link>
                        </Button>

                        <Button asChild variant="outline">
                            <Link href={agents(project)}>
                                <Users aria-hidden="true" />
                                Agents
                            </Link>
                        </Button>

                        <Button type="button" onClick={onNewWorkRequest}>
                            <Plus aria-hidden="true" />
                            New Work Request
                        </Button>
                    </div>
                </div>

                <RepositoryMeta
                    project={project}
                    repositoryStatus={repositoryStatus}
                />
            </CardHeader>
        </Card>
    );
}

/**
 * Render the existing manual WorkRequest submission contract inside an
 * accessible controlled dialog.
 */
function NewWorkRequestDialog({
    project,
    open,
    onOpenChange,
}: {
    project: Project;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>New Work Request</DialogTitle>
                    <DialogDescription>
                        Describe the work for the Project Manager. AISF will
                        analyze this request and produce Tasks through the
                        existing planning workflow.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...storeWorkRequest.form(project)}
                    resetOnSuccess={['prompt']}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="work-request-prompt">
                                    Work request
                                </Label>
                                <textarea
                                    id="work-request-prompt"
                                    name="prompt"
                                    required
                                    autoFocus
                                    placeholder="Describe the change, bug, feature, or investigation to plan…"
                                    className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring min-h-40 resize-y rounded-md border px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                />
                                <p className="text-muted-foreground text-xs">
                                    This submits to the existing WorkRequest
                                    planning pipeline. It does not create a Task
                                    directly.
                                </p>
                                <InputError message={errors.prompt} />
                            </div>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Submit for planning
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Render the existing Task run, continue and retry forms using exactly the
 * statuses supported by the current TaskController.
 */
function TaskExecutionActions({
    project,
    task,
}: {
    project: Project;
    task: Task;
}) {
    const canRun = task.status === 'pending' || task.status === 'waiting';

    return (
        <div className="grid gap-3">
            {task.status === 'failed' && (
                <div className="border-destructive/30 bg-destructive/5 rounded-lg border p-3">
                    <p className="text-destructive text-sm font-medium">
                        Task failed
                    </p>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {task.blocked_reason || 'No failure reason recorded.'}
                    </p>

                    <Form
                        {...retryTask.form([project.id, task.id])}
                        className="mt-3"
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <RotateCcw aria-hidden="true" />
                                )}
                                Retry
                            </Button>
                        )}
                    </Form>
                </div>
            )}

            {canRun && (
                <Form {...runTask.form([project.id, task.id])}>
                    {({ processing }) => (
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Play aria-hidden="true" />
                            )}
                            Run now
                        </Button>
                    )}
                </Form>
            )}

            {task.status === 'waiting' && task.last_handoff?.note && (
                <div className="bg-muted/60 rounded-lg p-3">
                    <p className="text-sm font-medium">
                        Agent handoff
                        {task.last_handoff.to_role
                            ? ` to ${humanize(task.last_handoff.to_role)}`
                            : ''}
                    </p>
                    <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                        {task.last_handoff.note}
                    </p>

                    <Form
                        {...runTask.form([project.id, task.id])}
                        className="mt-3 grid gap-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label
                                    htmlFor={`task-${task.id}-operator-instruction`}
                                    className="text-xs"
                                >
                                    Optional operator instruction
                                </Label>
                                <textarea
                                    id={`task-${task.id}-operator-instruction`}
                                    name="operator_instruction"
                                    placeholder="Instruction for the next Agent turn…"
                                    className="border-input bg-background focus-visible:ring-ring min-h-20 rounded-md border p-2 text-sm outline-none focus-visible:ring-2"
                                />
                                <InputError
                                    message={errors.operator_instruction}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="w-fit"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Continue
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            )}
        </div>
    );
}

/**
 * Render all durable Task information inside progressive disclosure rather than
 * forcing diagnostic detail onto the default card grid.
 */
function TaskInspectionDialog({
    project,
    task,
    request,
    dependency,
}: {
    project: Project;
    task: Task;
    request: WorkRequest;
    dependency: Task | null;
}) {
    const hasCandidateMetadata =
        task.branch_name !== null ||
        task.worktree_path !== null ||
        task.candidate_tree_sha !== null ||
        task.commit_sha !== null ||
        task.pull_request_url !== null ||
        task.changed_files.length > 0;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Inspect
                </Button>
            </DialogTrigger>

            <DialogContent className="max-h-[85vh] gap-0 overflow-hidden p-0 sm:max-w-5xl">
                <DialogHeader className="p-6 pb-4">
                    <div className="flex flex-wrap items-center gap-2 pr-8">
                        <StatusBadge status={task.status} />
                        {task.outcome === 'blocked' && (
                            <Badge variant="destructive">
                                <Ban aria-hidden="true" />
                                Blocked
                            </Badge>
                        )}
                    </div>
                    <DialogTitle className="text-xl">{task.title}</DialogTitle>
                    <DialogDescription>
                        Task #{task.id} · Planning position {task.position} ·
                        Work Request #{request.id}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid min-h-0 gap-4 overflow-y-auto px-6 pb-6">
                    <InspectionSection title="Overview">
                        <div className="grid gap-4">
                            <div>
                                <p className="text-muted-foreground text-xs">
                                    Objective
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {task.objective}
                                </p>
                            </div>

                            {task.implementation_spec && (
                                <div>
                                    <p className="text-muted-foreground text-xs">
                                        Implementation specification
                                    </p>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {task.implementation_spec}
                                    </p>
                                </div>
                            )}

                            <div>
                                <p className="text-muted-foreground text-xs">
                                    Parent WorkRequest
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {request.prompt}
                                </p>
                                {request.summary && (
                                    <p className="text-muted-foreground mt-2 text-sm whitespace-pre-wrap">
                                        PM summary: {request.summary}
                                    </p>
                                )}
                            </div>
                        </div>
                    </InspectionSection>

                    <InspectionSection title="Workflow">
                        <div className="grid gap-4">
                            <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <MetadataField label="Status">
                                    <StatusBadge status={task.status} />
                                </MetadataField>
                                <MetadataField label="Outcome">
                                    {task.outcome
                                        ? humanize(task.outcome)
                                        : 'Not recorded'}
                                </MetadataField>
                                <MetadataField label="Protocol recoveries">
                                    {task.protocol_recovery_count}
                                </MetadataField>
                                <MetadataField label="Repair cycles">
                                    {task.repair_cycle_count} of{' '}
                                    {task.repair_cycle_limit}
                                </MetadataField>
                            </dl>

                            {task.depends_on_task_id && (
                                <div>
                                    <p className="text-muted-foreground text-xs">
                                        Dependency
                                    </p>
                                    <p className="mt-1 text-sm">
                                        {dependency
                                            ? `Task #${dependency.id}: ${dependency.title}`
                                            : `Task #${task.depends_on_task_id}`}
                                    </p>
                                </div>
                            )}

                            {(task.outcome === 'blocked' ||
                                task.blocked_reason) && (
                                <div className="border-destructive/30 bg-destructive/5 rounded-lg border p-3">
                                    <p className="text-destructive text-sm font-medium">
                                        Blocking information
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                                        {task.blocked_reason ||
                                            'The Task has a blocked outcome without an additional recorded reason.'}
                                    </p>
                                </div>
                            )}

                            {task.last_handoff && (
                                <div>
                                    <p className="text-muted-foreground text-xs">
                                        Latest handoff
                                    </p>
                                    <p className="mt-1 text-sm capitalize">
                                        {task.last_handoff.to_role
                                            ? `To ${humanize(task.last_handoff.to_role)}`
                                            : 'Destination unavailable'}
                                    </p>
                                    {task.last_handoff.note && (
                                        <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                                            {task.last_handoff.note}
                                        </p>
                                    )}
                                </div>
                            )}

                            <TaskExecutionActions
                                project={project}
                                task={task}
                            />
                        </div>
                    </InspectionSection>

                    <InspectionSection title="Candidate and Repository">
                        {hasCandidateMetadata ? (
                            <div className="grid gap-4">
                                <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    <MetadataField label="Branch" mono>
                                        {task.branch_name ?? 'Unavailable'}
                                    </MetadataField>
                                    <MetadataField label="Candidate kind">
                                        {task.candidate_kind
                                            ? humanize(task.candidate_kind)
                                            : 'Unavailable'}
                                    </MetadataField>
                                    <MetadataField
                                        label="Candidate tree SHA"
                                        mono
                                    >
                                        {task.candidate_tree_sha ??
                                            'Unavailable'}
                                    </MetadataField>
                                    <MetadataField label="Commit SHA" mono>
                                        {task.commit_sha ?? 'Unavailable'}
                                    </MetadataField>
                                    <MetadataField label="Worktree path" mono>
                                        {task.worktree_path ?? 'Unavailable'}
                                    </MetadataField>
                                </dl>

                                {task.changed_files.length > 0 && (
                                    <div>
                                        <p className="text-muted-foreground text-xs">
                                            Changed files
                                        </p>
                                        <ul className="mt-2 grid gap-1 text-sm">
                                            {task.changed_files.map((file) => (
                                                <li
                                                    key={`${task.id}-${file}`}
                                                    className="bg-background/70 rounded-md px-2 py-1 font-mono text-xs break-all"
                                                >
                                                    {file}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {task.pull_request_url && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="w-fit"
                                    >
                                        <a
                                            href={task.pull_request_url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            View pull request
                                            <ExternalLink aria-hidden="true" />
                                        </a>
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                No candidate or task-worktree metadata has been
                                recorded yet.
                            </p>
                        )}
                    </InspectionSection>

                    <InspectionSection title="QA Reviews">
                        {task.candidate_reviews.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No QA review has been recorded for this Task
                                yet.
                            </p>
                        ) : (
                            <div className="grid gap-3">
                                {task.candidate_reviews.map((review, index) => (
                                    <article
                                        key={`${task.id}-review-${index}`}
                                        className="bg-background/70 rounded-lg p-3"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <ReviewStatusBadge
                                                status={review.status}
                                            />
                                            <span className="text-muted-foreground text-xs">
                                                {formatTimestamp(
                                                    review.created_at,
                                                )}
                                            </span>
                                        </div>

                                        {review.summary && (
                                            <p className="mt-3 text-sm">
                                                {review.summary}
                                            </p>
                                        )}

                                        {review.candidate_tree_sha && (
                                            <p className="text-muted-foreground mt-2 font-mono text-xs break-all">
                                                Candidate tree:{' '}
                                                {review.candidate_tree_sha}
                                            </p>
                                        )}

                                        <pre className="bg-muted mt-3 max-h-72 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">
                                            {serializeFindings(review.findings)}
                                        </pre>
                                    </article>
                                ))}
                            </div>
                        )}
                    </InspectionSection>

                    <InspectionSection title="Agent Activity">
                        <AgentSessionActivity
                            sessions={task.agent_sessions}
                            emptyLabel="No Agent session has been recorded for this Task yet."
                        />
                    </InspectionSection>

                    <InspectionSection title="Handoffs">
                        {task.handoffs.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No durable Task handoff history has been
                                recorded.
                            </p>
                        ) : (
                            <ol className="grid gap-2">
                                {task.handoffs.map((handoff) => (
                                    <li
                                        key={handoff.id}
                                        className="bg-background/70 rounded-md p-3"
                                    >
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <span className="text-sm capitalize">
                                                {humanize(
                                                    handoff.from_role ??
                                                        'unknown',
                                                )}{' '}
                                                →{' '}
                                                {humanize(
                                                    handoff.to_role ??
                                                        'unknown',
                                                )}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {formatTimestamp(
                                                    handoff.dispatched_at,
                                                )}
                                            </span>
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            {humanize(handoff.reason)}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </InspectionSection>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Render a concise scan-friendly Task card while keeping diagnostic data behind
 * the Inspect action.
 */
function TaskCard({
    project,
    item,
    dependency,
}: {
    project: Project;
    item: TaskItem;
    dependency: Task | null;
}) {
    const { task, request } = item;
    const recentAgent = task.agent_sessions[0]?.agent ?? null;
    const isBlocked =
        task.outcome === 'blocked' || Boolean(task.blocked_reason);

    return (
        <Card
            className={`h-full gap-0 py-0 ${
                task.status === 'running'
                    ? 'ring-primary/25 ring-2'
                    : task.status === 'completed'
                      ? 'shadow-none'
                      : ''
            }`}
        >
            <CardHeader className="gap-3 p-4">
                <div className="flex items-start justify-between gap-3">
                    <StatusBadge status={task.status} />
                    <span className="text-muted-foreground text-xs font-medium">
                        Task #{task.id}
                    </span>
                </div>

                <div>
                    <h3 className="line-clamp-2 text-base leading-5 font-semibold">
                        {task.title}
                    </h3>
                    <p className="text-muted-foreground mt-2 line-clamp-3 text-sm leading-5">
                        {task.objective}
                    </p>
                </div>
            </CardHeader>

            <CardContent className="grid flex-1 gap-3 px-4 pb-4">
                {isBlocked && (
                    <div className="border-destructive/30 bg-destructive/5 rounded-md border p-2.5">
                        <p className="text-destructive flex items-center gap-1.5 text-xs font-medium">
                            <Ban className="size-3.5" aria-hidden="true" />
                            Blocked
                        </p>
                        <p className="text-muted-foreground mt-1 line-clamp-2 text-xs">
                            {task.blocked_reason ||
                                'The Task is recorded with a blocked outcome.'}
                        </p>
                    </div>
                )}

                {task.depends_on_task_id && (
                    <div className="bg-muted/50 rounded-md p-2.5">
                        <p className="text-muted-foreground text-xs">
                            Depends on
                        </p>
                        <p className="mt-0.5 line-clamp-1 text-xs font-medium">
                            {dependency
                                ? `Task #${dependency.id}: ${dependency.title}`
                                : `Task #${task.depends_on_task_id}`}
                        </p>
                    </div>
                )}

                <div className="text-muted-foreground grid gap-2 text-xs">
                    {task.last_handoff?.to_role && (
                        <div className="flex items-center gap-2">
                            <Activity
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                Next handoff:{' '}
                                <span className="text-foreground capitalize">
                                    {humanize(task.last_handoff.to_role)}
                                </span>
                            </span>
                        </div>
                    )}

                    {recentAgent && (
                        <div className="flex items-center gap-2">
                            <Users
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="truncate">
                                Recent Agent:{' '}
                                <span className="text-foreground">
                                    {recentAgent.name}
                                </span>
                            </span>
                        </div>
                    )}

                    {task.branch_name && (
                        <div className="flex min-w-0 items-center gap-2">
                            <GitBranch
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="truncate font-mono">
                                {task.branch_name}
                            </span>
                        </div>
                    )}

                    {task.candidate_tree_sha && (
                        <div className="flex items-center gap-2">
                            <GitCommitHorizontal
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                Candidate{' '}
                                <span className="font-mono">
                                    {shortSha(task.candidate_tree_sha)}
                                </span>
                            </span>
                        </div>
                    )}
                </div>
            </CardContent>

            <CardFooter className="justify-between gap-3 px-4 py-3">
                <span className="text-muted-foreground text-xs">
                    Work Request #{request.id}
                </span>

                <TaskInspectionDialog
                    project={project}
                    task={task}
                    request={request}
                    dependency={dependency}
                />
            </CardFooter>
        </Card>
    );
}

/**
 * Render context-aware empty and transitional states for the Task dashboard.
 */
function TaskEmptyState({
    workRequests,
    onNewWorkRequest,
}: {
    workRequests: WorkRequest[];
    onNewWorkRequest: () => void;
}) {
    const activePlanning = workRequests.find((request) =>
        ['pending', 'running', 'waiting'].includes(request.status),
    );
    const alreadyImplemented = workRequests.find(
        (request) => request.outcome === 'already_implemented',
    );

    if (workRequests.length === 0) {
        return (
            <div className="border-border bg-card flex min-h-72 flex-col items-center justify-center rounded-xl border p-8 text-center">
                <FolderGit2 className="text-muted-foreground size-9" />
                <h3 className="mt-4 text-base font-semibold">
                    No work requests yet
                </h3>
                <p className="text-muted-foreground mt-2 max-w-md text-sm">
                    Submit a WorkRequest to let the Project Manager analyze it
                    and plan implementation Tasks.
                </p>
                <Button
                    type="button"
                    className="mt-5"
                    onClick={onNewWorkRequest}
                >
                    <Plus aria-hidden="true" />
                    New Work Request
                </Button>
            </div>
        );
    }

    if (activePlanning) {
        return (
            <div className="border-border bg-card flex min-h-64 flex-col items-center justify-center rounded-xl border p-8 text-center">
                <LoaderCircle className="text-primary size-8 motion-safe:animate-spin motion-reduce:animate-none" />
                <h3 className="mt-4 text-base font-semibold">
                    Planning in progress
                </h3>
                <p className="text-muted-foreground mt-2 max-w-lg text-sm">
                    Work Request #{activePlanning.id} is currently{' '}
                    {humanize(activePlanning.status)}. Tasks will appear here
                    when planning produces them.
                </p>
            </div>
        );
    }

    if (alreadyImplemented) {
        return (
            <div className="border-border bg-card flex min-h-64 flex-col items-center justify-center rounded-xl border p-8 text-center">
                <CheckCircle2 className="text-muted-foreground size-8" />
                <h3 className="mt-4 text-base font-semibold">
                    No implementation Tasks required
                </h3>
                <p className="text-muted-foreground mt-2 max-w-lg text-sm">
                    Work Request #{alreadyImplemented.id} was recorded as
                    already implemented.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    className="mt-5"
                    onClick={onNewWorkRequest}
                >
                    <Plus aria-hidden="true" />
                    New Work Request
                </Button>
            </div>
        );
    }
}

/**
 * Render Tasks as the dominant responsive dashboard surface.
 */
function TasksSection({
    project,
    taskItems,
    workRequests,
    onNewWorkRequest,
}: {
    project: Project;
    taskItems: TaskItem[];
    workRequests: WorkRequest[];
    onNewWorkRequest: () => void;
}) {
    return (
        <section aria-labelledby="tasks-heading">
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h2
                            id="tasks-heading"
                            className="text-xl font-semibold tracking-tight"
                        >
                            Tasks
                        </h2>
                        <Badge variant="secondary">{taskItems.length}</Badge>
                    </div>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Active work and durable progress across this project.
                    </p>
                </div>
            </div>

            {taskItems.length === 0 ? (
                <TaskEmptyState
                    workRequests={workRequests}
                    onNewWorkRequest={onNewWorkRequest}
                />
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {taskItems.map((item) => {
                        const dependency = item.task.depends_on_task_id
                            ? (taskItems.find(
                                  (candidate) =>
                                      candidate.task.id ===
                                      item.task.depends_on_task_id,
                              )?.task ?? null)
                            : null;

                        return (
                            <TaskCard
                                key={item.task.id}
                                project={project}
                                item={item}
                                dependency={dependency}
                            />
                        );
                    })}
                </div>
            )}
        </section>
    );
}
/**
 * Render the Project control surface with Tasks prioritized for scanning and
 * durable diagnostic information progressively disclosed on demand.
 */
export default function ProjectWorkspace({
    project,
    repositoryStatus,
    workRequests = [],
}: {
    project: Project;
    repositoryStatus: RepositoryStatus | null;
    workRequests?: WorkRequest[];
}) {
    const [newWorkRequestOpen, setNewWorkRequestOpen] = useState(false);

    const taskItems: TaskItem[] = workRequests.flatMap((request) =>
        request.tasks.map((task) => ({
            task,
            request,
        })),
    );

    return (
        <TooltipProvider>
            <Head title={project.title} />

            <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-6 p-4 md:p-6 xl:p-8">
                <ProjectOverview
                    project={project}
                    repositoryStatus={repositoryStatus}
                    onNewWorkRequest={() => setNewWorkRequestOpen(true)}
                />

                <TasksSection
                    project={project}
                    taskItems={taskItems}
                    workRequests={workRequests}
                    onNewWorkRequest={() => setNewWorkRequestOpen(true)}
                />
            </div>

            <NewWorkRequestDialog
                project={project}
                open={newWorkRequestOpen}
                onOpenChange={setNewWorkRequestOpen}
            />
        </TooltipProvider>
    );
}

ProjectWorkspace.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
