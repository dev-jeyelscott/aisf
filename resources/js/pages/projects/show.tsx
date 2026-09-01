import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    GitBranch,
    Pencil,
    Users,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/projects';
import { index as agents } from '@/routes/projects/agents';
import { retry as retryTask, run as runTask } from '@/routes/projects/tasks';
import {
    retry as retryWorkRequest,
    store as storeWorkRequest,
} from '@/routes/projects/work-requests';

type Project = {
    id: number;
    title: string;
    description: string | null;
    path: string;
    enabled: boolean;
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

type Task = {
    id: number;
    depends_on_task_id: number | null;
    position: number;
    title: string;
    objective: string;
    implementation_spec: string;
    status:
        | 'pending'
        | 'running'
        | 'waiting'
        | 'completed'
        | 'failed'
        | 'cancelled';
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
    agent_sessions: AgentSession[];
    handoffs: HandoffRecord[];
    repair_cycle_count: number;
    repair_cycle_limit: number;
};

type WorkRequest = {
    id: number;
    prompt: string;
    status:
        | 'pending'
        | 'running'
        | 'waiting'
        | 'completed'
        | 'failed'
        | 'cancelled';
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

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

/**
 * Return status styling while retaining visible text so status is never communicated by color alone.
 */
function statusClass(status: string): string {
    if (status === 'completed') {
        return 'border-green-600/30 bg-green-600/10 text-green-700 dark:text-green-400';
    }

    if (status === 'failed') {
        return 'border-destructive/30 bg-destructive/10 text-destructive';
    }

    if (status === 'running') {
        return 'border-amber-600/30 bg-amber-600/10 text-amber-700 dark:text-amber-400';
    }

    return 'border-border bg-muted text-muted-foreground';
}

/**
 * Render logical Agent continuity and recent model invocation details.
 */
function AgentSessionActivity({
    sessions,
    emptyLabel,
}: {
    sessions: AgentSession[];
    emptyLabel: string;
}) {
    if (sessions.length === 0) {
        return (
            <p className="text-muted-foreground mt-3 text-sm">{emptyLabel}</p>
        );
    }

    return (
        <div className="mt-4 space-y-3">
            {sessions.map((session) => (
                <div
                    key={session.id}
                    className="border-border bg-muted/20 rounded-lg border p-3"
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
                        <span className="border-border bg-background rounded-full border px-2 py-1 text-xs">
                            {session.has_provider_continuity
                                ? 'Provider resume available'
                                : 'Logical continuity only'}
                        </span>
                    </div>

                    <div className="mt-3 space-y-3">
                        {session.runs.map((run) => (
                            <article
                                key={run.id}
                                className="border-border bg-background rounded-md border p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">
                                            Run {run.attempt}
                                        </span>
                                        <span
                                            className={`rounded-full border px-2 py-0.5 text-xs font-medium capitalize ${statusClass(run.status === 'succeeded' ? 'completed' : run.status)}`}
                                        >
                                            {humanize(run.status)}
                                        </span>
                                        {run.reconciliation_status && (
                                            <span className="text-muted-foreground text-xs">
                                                {humanize(
                                                    run.reconciliation_status,
                                                )}
                                                {run.failure_class &&
                                                    ` · ${humanize(run.failure_class)}`}
                                            </span>
                                        )}
                                    </div>
                                    <span className="text-muted-foreground text-xs capitalize">
                                        {humanize(run.purpose)}
                                    </span>
                                </div>

                                <p className="text-muted-foreground mt-2 text-sm">
                                    {run.output_summary ??
                                        'No concise output summary recorded.'}
                                </p>

                                <dl className="text-muted-foreground mt-3 grid gap-2 text-xs sm:grid-cols-3">
                                    <div>
                                        <dt>Started</dt>
                                        <dd className="text-foreground mt-0.5">
                                            {formatTimestamp(run.started_at)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Finished</dt>
                                        <dd className="text-foreground mt-0.5">
                                            {formatTimestamp(run.finished_at)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Exit code</dt>
                                        <dd className="text-foreground mt-0.5">
                                            {run.exit_code ?? 'Unavailable'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Harness</dt>
                                        <dd className="text-foreground mt-0.5 capitalize">
                                            {run.harness ?? 'Unavailable'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Model</dt>
                                        <dd className="text-foreground mt-0.5">
                                            {run.model ?? 'Unavailable'}
                                        </dd>
                                    </div>
                                </dl>

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
                            </article>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * Render the existing Project workspace with planning, Tasks, and durable Agent activity.
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
    const allSessions = workRequests.flatMap((request) => [
        ...request.agent_sessions,
        ...request.tasks.flatMap((task) => task.agent_sessions),
    ]);
    const visibleRunCount = allSessions.reduce(
        (total, session) => total + session.runs.length,
        0,
    );

    return (
        <>
            <Head title={project.title} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <p className="text-muted-foreground text-sm">
                            Project workspace
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {project.title}
                        </h1>
                        {project.description && (
                            <p className="text-muted-foreground mt-2 max-w-2xl">
                                {project.description}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={edit(project)}>
                                <Pencil />
                                Edit Project
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={agents(project)}>
                                <Users />
                                Agents
                            </Link>
                        </Button>
                    </div>
                </div>

                <section
                    aria-labelledby="repository-heading"
                    className="border-border bg-card rounded-xl border p-5"
                >
                    <h2 id="repository-heading" className="font-medium">
                        Repository
                    </h2>
                    <p className="text-muted-foreground mt-2 font-mono text-sm break-all">
                        {project.path}
                    </p>
                    {!project.enabled ? (
                        <p className="text-muted-foreground mt-4 text-sm">
                            This project is disabled.
                        </p>
                    ) : repositoryStatus ? (
                        <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground">
                                    Reference
                                </dt>
                                <dd className="mt-1 flex items-center gap-2 font-medium">
                                    <GitBranch className="size-4" />
                                    {repositoryStatus.branch ?? 'Detached HEAD'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">HEAD</dt>
                                <dd className="mt-1 font-mono font-medium">
                                    {repositoryStatus.headSha}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Working tree
                                </dt>
                                <dd className="mt-1 flex items-center gap-2 font-medium">
                                    {repositoryStatus.isClean ? (
                                        <CheckCircle2 className="size-4 text-green-600" />
                                    ) : (
                                        <AlertCircle className="size-4 text-amber-600" />
                                    )}
                                    {repositoryStatus.isClean
                                        ? 'Clean'
                                        : 'Dirty'}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <div className="bg-muted text-muted-foreground mt-4 flex items-center gap-2 rounded-md p-3 text-sm">
                            <AlertCircle className="size-4" />
                            Repository status is unavailable. Check the path and
                            Git repository, then try again.
                        </div>
                    )}
                </section>

                <section className="border-border bg-card rounded-xl border p-5">
                    <h2 className="font-medium">Prompt</h2>
                    <Form
                        {...storeWorkRequest.form(project)}
                        resetOnSuccess={['prompt']}
                        className="mt-3 grid gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <textarea
                                    name="prompt"
                                    required
                                    placeholder="Describe the work to plan…"
                                    className="border-input bg-background min-h-24 rounded-md border p-3 text-sm"
                                />
                                <InputError message={errors.prompt} />
                                <Button type="submit" disabled={processing}>
                                    Submit for planning
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                {workRequests.map((request) => (
                    <section
                        key={request.id}
                        className="border-border bg-card rounded-xl border p-5"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-medium">Work request</h2>
                                {request.source_type !== 'manual' && (
                                    <span className="border-border bg-muted text-muted-foreground rounded-full border px-2 py-0.5 text-xs font-medium capitalize">
                                        {request.source_url ? (
                                            <a
                                                href={request.source_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="underline underline-offset-2"
                                            >
                                                {request.source_type}
                                            </a>
                                        ) : (
                                            request.source_type
                                        )}
                                    </span>
                                )}
                            </div>
                            <span
                                className={`rounded-full border px-2.5 py-1 text-xs font-medium capitalize ${statusClass(request.status)}`}
                            >
                                {humanize(request.status)}
                                {request.outcome &&
                                    ` · ${humanize(request.outcome)}`}
                            </span>
                        </div>

                        <p className="mt-3 text-sm whitespace-pre-wrap">
                            {request.prompt}
                        </p>

                        {request.summary && (
                            <div className="mt-4">
                                <h3 className="text-sm font-medium">
                                    PM summary
                                </h3>
                                <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                                    {request.summary}
                                </p>
                            </div>
                        )}

                        <div className="mt-4">
                            <h3 className="text-sm font-medium">
                                Project Manager activity
                            </h3>
                            <AgentSessionActivity
                                sessions={request.agent_sessions}
                                emptyLabel="No Project Manager session has been recorded for this WorkRequest yet."
                            />
                        </div>

                        {request.evidence && request.evidence.length > 0 && (
                            <div className="mt-4">
                                <h3 className="text-sm font-medium">
                                    Repository evidence
                                </h3>
                                <ul className="text-muted-foreground mt-2 list-disc space-y-1 pl-5 text-sm">
                                    {request.evidence.map((evidence, index) => (
                                        <li
                                            key={`${request.id}-evidence-${index}`}
                                        >
                                            {evidence}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {request.status === 'failed' && (
                            <div className="border-destructive/30 bg-destructive/5 mt-4 rounded-md border p-3">
                                <h3 className="text-destructive text-sm font-medium">
                                    Work request failed
                                </h3>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {request.failure_reason}
                                </p>
                                <Form
                                    {...retryWorkRequest.form([
                                        project.id,
                                        request.id,
                                    ])}
                                    className="mt-3"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Retry
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        )}

                        {request.tasks.length > 0 && (
                            <div className="mt-5 space-y-4">
                                <h3 className="text-sm font-medium">Tasks</h3>
                                {request.tasks.map((task) => {
                                    const dependency = task.depends_on_task_id
                                        ? request.tasks.find(
                                              (candidate) =>
                                                  candidate.id ===
                                                  task.depends_on_task_id,
                                          )
                                        : null;

                                    return (
                                        <article
                                            key={task.id}
                                            className="border-border bg-background rounded-lg border p-4"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                                                        Task {task.position}
                                                    </p>
                                                    <h4 className="mt-1 font-medium">
                                                        {task.title}
                                                    </h4>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {dependency && (
                                                        <span className="text-muted-foreground text-xs">
                                                            Depends on Task{' '}
                                                            {
                                                                dependency.position
                                                            }
                                                            : {dependency.title}
                                                        </span>
                                                    )}
                                                    <span
                                                        className={`rounded-full border px-2.5 py-1 text-xs font-medium capitalize ${statusClass(task.status)}`}
                                                    >
                                                        {humanize(task.status)}
                                                        {task.outcome &&
                                                            ` · ${humanize(task.outcome)}`}
                                                    </span>
                                                    {task.repair_cycle_count >
                                                        0 && (
                                                        <span className="border-border bg-muted text-muted-foreground rounded-full border px-2.5 py-1 text-xs font-medium">
                                                            Repair cycle{' '}
                                                            {
                                                                task.repair_cycle_count
                                                            }{' '}
                                                            of{' '}
                                                            {
                                                                task.repair_cycle_limit
                                                            }
                                                        </span>
                                                    )}
                                                    {(task.status ===
                                                        'pending' ||
                                                        task.status ===
                                                            'waiting') && (
                                                        <Form
                                                            {...runTask.form([
                                                                project.id,
                                                                task.id,
                                                            ])}
                                                            className="flex items-center gap-2"
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    type="submit"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    Run now
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                </div>
                                            </div>

                                            {task.status === 'failed' && (
                                                <div className="border-destructive/30 bg-destructive/5 mt-4 rounded-md border p-3">
                                                    <h5 className="text-destructive text-sm font-medium">
                                                        Task failed
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        {task.blocked_reason}
                                                    </p>
                                                    <Form
                                                        {...retryTask.form([
                                                            project.id,
                                                            task.id,
                                                        ])}
                                                        className="mt-3"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Retry
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            )}

                                            {task.status === 'waiting' &&
                                                task.last_handoff?.note && (
                                                    <div className="mt-4 rounded-md border border-amber-600/30 bg-amber-600/10 p-3">
                                                        <h5 className="text-sm font-medium text-amber-700 dark:text-amber-400">
                                                            Agent handoff
                                                            {task.last_handoff
                                                                .to_role
                                                                ? ` to ${humanize(task.last_handoff.to_role)}`
                                                                : ''}
                                                        </h5>
                                                        <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                                                            {
                                                                task
                                                                    .last_handoff
                                                                    .note
                                                            }
                                                        </p>
                                                        <Form
                                                            {...runTask.form([
                                                                project.id,
                                                                task.id,
                                                            ])}
                                                            className="mt-3 grid gap-2"
                                                        >
                                                            {({
                                                                processing,
                                                                errors,
                                                            }) => (
                                                                <>
                                                                    <textarea
                                                                        name="operator_instruction"
                                                                        placeholder="Optional operator instruction for the next Agent turn…"
                                                                        className="border-input bg-background min-h-16 rounded-md border p-2 text-sm"
                                                                    />
                                                                    <InputError
                                                                        message={
                                                                            errors.operator_instruction
                                                                        }
                                                                    />
                                                                    <Button
                                                                        type="submit"
                                                                        size="sm"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        Continue
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </Form>
                                                    </div>
                                                )}

                                            {task.commit_sha && (
                                                <div className="border-border bg-muted/20 mt-4 rounded-md border p-3">
                                                    <h5 className="text-sm font-medium">
                                                        Commit
                                                    </h5>
                                                    <p className="text-foreground mt-1 font-mono text-xs break-all">
                                                        {task.commit_sha}
                                                    </p>
                                                    {task.pull_request_url && (
                                                        <p className="mt-2 text-sm">
                                                            <a
                                                                href={
                                                                    task.pull_request_url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-foreground underline underline-offset-2"
                                                            >
                                                                View pull
                                                                request
                                                            </a>
                                                        </p>
                                                    )}
                                                </div>
                                            )}

                                            {task.handoffs.length > 0 && (
                                                <details className="border-border mt-4 rounded-md border">
                                                    <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                                        Handoff history (
                                                        {task.handoffs.length})
                                                    </summary>
                                                    <ol className="border-border divide-border divide-y border-t text-sm">
                                                        {task.handoffs.map(
                                                            (handoff) => (
                                                                <li
                                                                    key={
                                                                        handoff.id
                                                                    }
                                                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                                                >
                                                                    <span className="capitalize">
                                                                        {humanize(
                                                                            handoff.from_role ??
                                                                                'unknown',
                                                                        )}{' '}
                                                                        →{' '}
                                                                        {humanize(
                                                                            handoff.to_role ??
                                                                                'unknown',
                                                                        )}{' '}
                                                                        ·{' '}
                                                                        {humanize(
                                                                            handoff.reason,
                                                                        )}
                                                                    </span>
                                                                    <span className="text-muted-foreground text-xs">
                                                                        {formatTimestamp(
                                                                            handoff.dispatched_at,
                                                                        )}
                                                                    </span>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ol>
                                                </details>
                                            )}

                                            {task.worktree_path && (
                                                <div className="border-border bg-muted/20 mt-4 rounded-md border p-3">
                                                    <h5 className="text-sm font-medium">
                                                        Task worktree
                                                    </h5>
                                                    <dl className="text-muted-foreground mt-2 grid gap-2 text-xs sm:grid-cols-2">
                                                        <div>
                                                            <dt>Branch</dt>
                                                            <dd className="text-foreground mt-0.5 font-mono">
                                                                {
                                                                    task.branch_name
                                                                }
                                                            </dd>
                                                        </div>
                                                        <div>
                                                            <dt>
                                                                Worktree path
                                                            </dt>
                                                            <dd className="text-foreground mt-0.5 font-mono break-all">
                                                                {
                                                                    task.worktree_path
                                                                }
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                    {task.changed_files.length >
                                                        0 && (
                                                        <div className="mt-3">
                                                            <h6 className="text-xs font-medium">
                                                                Changed files
                                                            </h6>
                                                            <ul className="text-muted-foreground mt-1 list-disc space-y-1 pl-5 text-xs">
                                                                {task.changed_files.map(
                                                                    (file) => (
                                                                        <li
                                                                            key={`${task.id}-changed-${file}`}
                                                                        >
                                                                            {
                                                                                file
                                                                            }
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            <div className="mt-4 grid gap-4 text-sm">
                                                <div>
                                                    <h5 className="font-medium">
                                                        Objective
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 whitespace-pre-wrap">
                                                        {task.objective}
                                                    </p>
                                                </div>

                                                {task.implementation_spec && (
                                                    <div>
                                                        <h5 className="font-medium">
                                                            Implementation notes
                                                        </h5>
                                                        <p className="text-muted-foreground mt-1 whitespace-pre-wrap">
                                                            {
                                                                task.implementation_spec
                                                            }
                                                        </p>
                                                    </div>
                                                )}
                                            </div>

                                            <div className="border-border mt-5 border-t pt-4">
                                                <h5 className="text-sm font-medium">
                                                    Agent sessions and runs
                                                </h5>
                                                <AgentSessionActivity
                                                    sessions={
                                                        task.agent_sessions
                                                    }
                                                    emptyLabel="No Agent session has been recorded for this Task yet."
                                                />
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        )}
                    </section>
                ))}

                <section
                    aria-labelledby="activity-heading"
                    className="border-border bg-card rounded-xl border p-5"
                >
                    <h2 id="activity-heading" className="font-medium">
                        Activity
                    </h2>
                    <p className="text-muted-foreground mt-2 text-sm">
                        {allSessions.length} logical Agent session
                        {allSessions.length === 1 ? '' : 's'} and{' '}
                        {visibleRunCount} recent run
                        {visibleRunCount === 1 ? '' : 's'} are visible above in
                        their WorkRequest or Task context.
                    </p>
                </section>
            </div>
        </>
    );
}

ProjectWorkspace.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
