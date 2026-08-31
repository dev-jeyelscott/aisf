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
import {
    commit as commitTask,
    resume as resumeTask,
    start as startTask,
} from '@/routes/projects/tasks';
import {
    confirmBrowserCheck,
    store as startQaReview,
} from '@/routes/projects/tasks/qa-reviews';
import { store as storeWorkRequest } from '@/routes/projects/work-requests';

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
    context_mode: 'initial' | 'delta';
    submitted_input: string;
    context_sources: ContextSource[];
    output_summary: string | null;
    exit_code: number | null;
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

type AcceptanceCriterionResult = {
    criterion: string;
    met: boolean;
    note: string;
};

type VerificationResult = {
    command: string;
    passed: boolean;
    notes: string;
};

type BrowserResult = {
    mode: 'automated' | 'manual';
    passed: boolean | null;
    notes: string;
};

type QaReview = {
    id: number;
    status: string;
    summary: string;
    acceptance_criteria_results: AcceptanceCriterionResult[];
    verification_results: VerificationResult[];
    browser_result: BrowserResult;
    findings: string[];
    operator_confirmed_at: string | null;
    created_at: string | null;
};

type Task = {
    id: number;
    depends_on_task_id: number | null;
    position: number;
    title: string;
    objective: string;
    implementation_spec: string;
    acceptance_criteria: string[];
    verification_commands: string[];
    browser_steps: string[];
    status: string;
    base_branch: string | null;
    base_sha: string | null;
    branch_name: string | null;
    worktree_path: string | null;
    blocked_reason: string | null;
    approved_at: string | null;
    commit_sha: string | null;
    commit_message: string | null;
    integrated_sha: string | null;
    integrated_at: string | null;
    worktree_cleaned_at: string | null;
    branch_deleted_at: string | null;
    changed_files: string[];
    agent_sessions: AgentSession[];
    qa_reviews: QaReview[];
};

type WorkRequest = {
    id: number;
    prompt: string;
    status: string;
    summary: string | null;
    evidence: string[] | null;
    failure_reason: string | null;
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
function runStatusClass(status: string): string {
    if (status === 'succeeded') {
        return 'border-green-600/30 bg-green-600/10 text-green-700 dark:text-green-400';
    }

    if (status === 'failed') {
        return 'border-destructive/30 bg-destructive/10 text-destructive';
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
                                            className={`rounded-full border px-2 py-0.5 text-xs font-medium capitalize ${runStatusClass(run.status)}`}
                                        >
                                            {humanize(run.status)}
                                        </span>
                                        <span className="border-border bg-muted rounded-full border px-2 py-0.5 text-xs capitalize">
                                            {run.context_mode} context
                                        </span>
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
                                </dl>

                                <details className="border-border mt-3 rounded-md border">
                                    <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                        Inspect context sources and submitted
                                        input
                                    </summary>
                                    <div className="border-border border-t p-3">
                                        <h6 className="text-sm font-medium">
                                            Context sources
                                        </h6>
                                        {run.context_sources.length > 0 ? (
                                            <ul className="text-muted-foreground mt-2 list-disc space-y-1 pl-5 text-sm">
                                                {run.context_sources.map(
                                                    (source, index) => (
                                                        <li
                                                            key={`${run.id}-source-${index}`}
                                                        >
                                                            {source.label}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ) : (
                                            <p className="text-muted-foreground mt-2 text-sm">
                                                No source metadata recorded.
                                            </p>
                                        )}

                                        <h6 className="mt-4 text-sm font-medium">
                                            Exact submitted input
                                        </h6>
                                        <pre className="bg-muted mt-2 max-h-96 overflow-auto rounded-md p-3 text-xs break-words whitespace-pre-wrap">
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
 * Return status styling for a QA review outcome without communicating status by color alone.
 */
function qaStatusClass(status: string): string {
    if (status === 'approved') {
        return 'border-green-600/30 bg-green-600/10 text-green-700 dark:text-green-400';
    }

    if (status === 'changes_required') {
        return 'border-destructive/30 bg-destructive/10 text-destructive';
    }

    return 'border-amber-600/30 bg-amber-600/10 text-amber-700 dark:text-amber-400';
}

/**
 * Render QA review evidence, most recent first, including acceptance criteria, verification, browser, and findings.
 */
function QaReviewEvidence({ reviews }: { reviews: QaReview[] }) {
    return (
        <div className="mt-3 space-y-3">
            {reviews.map((review) => (
                <article
                    key={review.id}
                    className="border-border bg-muted/20 rounded-lg border p-3"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span
                            className={`rounded-full border px-2 py-0.5 text-xs font-medium capitalize ${qaStatusClass(review.status)}`}
                        >
                            {humanize(review.status)}
                        </span>
                        <span className="text-muted-foreground text-xs">
                            {formatTimestamp(review.created_at)}
                        </span>
                    </div>

                    <p className="text-muted-foreground mt-2 text-sm">
                        {review.summary}
                    </p>

                    <div className="mt-3">
                        <h6 className="text-xs font-medium">
                            Acceptance criteria
                        </h6>
                        <ul className="mt-1 space-y-1">
                            {review.acceptance_criteria_results.map(
                                (result, index) => (
                                    <li
                                        key={`${review.id}-criterion-${index}`}
                                        className="text-muted-foreground flex items-start gap-2 text-xs"
                                    >
                                        {result.met ? (
                                            <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-green-600" />
                                        ) : (
                                            <AlertCircle className="text-destructive mt-0.5 size-3.5 shrink-0" />
                                        )}
                                        <span>
                                            {result.criterion}
                                            {result.note ? ` — ${result.note}` : ''}
                                        </span>
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>

                    <div className="mt-3">
                        <h6 className="text-xs font-medium">
                            Verification results
                        </h6>
                        <ul className="mt-1 space-y-1">
                            {review.verification_results.map(
                                (result, index) => (
                                    <li
                                        key={`${review.id}-verification-${index}`}
                                        className="text-muted-foreground text-xs"
                                    >
                                        <div className="flex items-start gap-2">
                                            {result.passed ? (
                                                <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-green-600" />
                                            ) : (
                                                <AlertCircle className="text-destructive mt-0.5 size-3.5 shrink-0" />
                                            )}
                                            <code className="bg-muted rounded px-1.5 py-0.5">
                                                {result.command}
                                            </code>
                                        </div>
                                        {result.notes && (
                                            <p className="mt-1 pl-6">
                                                {result.notes}
                                            </p>
                                        )}
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>

                    <div className="mt-3">
                        <h6 className="text-xs font-medium">
                            Browser acceptance
                        </h6>
                        <p className="text-muted-foreground mt-1 text-xs">
                            {review.browser_result.mode === 'automated'
                                ? review.browser_result.passed
                                    ? 'Automated browser check passed.'
                                    : 'Automated browser check failed.'
                                : 'Automated browser tooling was unavailable — manual operator confirmation required.'}
                            {review.browser_result.notes
                                ? ` ${review.browser_result.notes}`
                                : ''}
                        </p>
                        {review.operator_confirmed_at && (
                            <p className="mt-1 text-xs text-green-700 dark:text-green-400">
                                Operator confirmed the browser check at{' '}
                                {formatTimestamp(review.operator_confirmed_at)}
                                .
                            </p>
                        )}
                    </div>

                    {review.findings.length > 0 && (
                        <div className="mt-3">
                            <h6 className="text-xs font-medium">Findings</h6>
                            <ul className="text-muted-foreground mt-1 list-disc space-y-1 pl-5 text-xs">
                                {review.findings.map((finding, index) => (
                                    <li key={`${review.id}-finding-${index}`}>
                                        {finding}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </article>
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
                            <h2 className="font-medium">Work request</h2>
                            <span className="border-border bg-muted rounded-full border px-2.5 py-1 text-xs font-medium capitalize">
                                {humanize(request.status)}
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

                        {request.failure_reason && (
                            <div className="border-destructive/30 bg-destructive/5 mt-4 rounded-md border p-3">
                                <h3 className="text-destructive text-sm font-medium">
                                    Planning failed
                                </h3>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {request.failure_reason}
                                </p>
                            </div>
                        )}

                        {request.tasks.length > 0 && (
                            <div className="mt-5 space-y-4">
                                <h3 className="text-sm font-medium">
                                    Ordered task plan
                                </h3>
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
                                                    <span className="border-border bg-muted rounded-full border px-2.5 py-1 text-xs font-medium capitalize">
                                                        {humanize(task.status)}
                                                    </span>
                                                    {task.status ===
                                                        'queued' && (
                                                        <Form
                                                            {...startTask.form([
                                                                project.id,
                                                                task.id,
                                                            ])}
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
                                                                    Start Task
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                    {task.status ===
                                                        'ready_for_qa' && (
                                                        <Form
                                                            {...startQaReview.form(
                                                                [
                                                                    project.id,
                                                                    task.id,
                                                                ],
                                                            )}
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
                                                                    Start QA
                                                                    review
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                </div>
                                            </div>

                                            {task.blocked_reason && (
                                                <div className="border-destructive/30 bg-destructive/5 mt-4 rounded-md border p-3">
                                                    <h5 className="text-destructive text-sm font-medium">
                                                        Task blocked
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        {task.blocked_reason}
                                                    </p>
                                                </div>
                                            )}

                                            {task.status === 'approved' && (
                                                <div className="mt-4 rounded-md border border-green-600/30 bg-green-600/10 p-3">
                                                    <h5 className="text-sm font-medium text-green-700 dark:text-green-400">
                                                        QA approved
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        Approved at{' '}
                                                        {formatTimestamp(
                                                            task.approved_at,
                                                        )}
                                                        . Verification checks
                                                        and browser acceptance
                                                        are recorded below.
                                                    </p>
                                                    <Form
                                                        {...commitTask.form([
                                                            project.id,
                                                            task.id,
                                                        ])}
                                                        className="mt-3"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Finalize approved
                                                                commit
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            )}

                                            {(task.status === 'committing' ||
                                                task.status ===
                                                    'integrating') && (
                                                <div className="mt-4 rounded-md border border-amber-600/30 bg-amber-600/10 p-3">
                                                    <h5 className="text-sm font-medium text-amber-700 dark:text-amber-400">
                                                        {task.status ===
                                                        'committing'
                                                            ? 'Coder is finalizing the approved commit'
                                                            : 'Integrating approved commit'}
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        The Task remains QA-gated;
                                                        integration performs no
                                                        additional AI review.
                                                    </p>
                                                </div>
                                            )}

                                            {task.commit_sha && (
                                                <div className="border-border bg-muted/20 mt-4 rounded-md border p-3">
                                                    <h5 className="text-sm font-medium">
                                                        Approved commit
                                                    </h5>
                                                    <dl className="text-muted-foreground mt-2 grid gap-2 text-xs sm:grid-cols-2">
                                                        <div>
                                                            <dt>Message</dt>
                                                            <dd className="text-foreground mt-0.5 font-mono break-words">
                                                                {
                                                                    task.commit_message
                                                                }
                                                            </dd>
                                                        </div>
                                                        <div>
                                                            <dt>SHA</dt>
                                                            <dd className="text-foreground mt-0.5 font-mono break-all">
                                                                {task.commit_sha}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                    {task.integrated_sha && (
                                                        <p className="text-muted-foreground mt-3 text-sm">
                                                            Integrated successfully
                                                            at{' '}
                                                            {formatTimestamp(
                                                                task.integrated_at,
                                                            )}
                                                            . Project HEAD: {' '}
                                                            <code className="bg-muted rounded px-1.5 py-0.5 text-xs">
                                                                {
                                                                    task.integrated_sha
                                                                }
                                                            </code>
                                                        </p>
                                                    )}
                                                    {task.worktree_cleaned_at && (
                                                        <p className="text-muted-foreground mt-2 text-sm">
                                                            Task worktree cleaned
                                                            up at{' '}
                                                            {formatTimestamp(
                                                                task.worktree_cleaned_at,
                                                            )}
                                                            {task.branch_deleted_at
                                                                ? '; temporary Task branch deleted.'
                                                                : '; temporary Task branch retained because it could not be safely deleted.'}
                                                        </p>
                                                    )}
                                                </div>
                                            )}

                                            {task.status ===
                                                'changes_required' && (
                                                <div className="border-destructive/30 bg-destructive/5 mt-4 rounded-md border p-3">
                                                    <h5 className="text-destructive text-sm font-medium">
                                                        QA changes required
                                                    </h5>
                                                    <Form
                                                        {...resumeTask.form([
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
                                                                    placeholder="Optional new operator instruction for the Coder…"
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
                                                                    Resume
                                                                    Coder with
                                                                    QA findings
                                                                </Button>
                                                            </>
                                                        )}
                                                    </Form>
                                                </div>
                                            )}

                                            {task.status ===
                                                'manual_browser_check_required' && (
                                                <div className="mt-4 rounded-md border border-amber-600/30 bg-amber-600/10 p-3">
                                                    <h5 className="text-sm font-medium text-amber-700 dark:text-amber-400">
                                                        Manual browser check
                                                        required
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 text-sm">
                                                        No automated browser
                                                        tooling was available.
                                                        Follow the browser
                                                        test steps below in
                                                        your own browser, then
                                                        confirm the result.
                                                    </p>
                                                    <Form
                                                        {...confirmBrowserCheck.form(
                                                            [
                                                                project.id,
                                                                task.id,
                                                            ],
                                                        )}
                                                        className="mt-3"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Confirm browser
                                                                check passed
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            )}

                                            {task.qa_reviews.length > 0 && (
                                                <div className="mt-4">
                                                    <h5 className="text-sm font-medium">
                                                        QA review evidence
                                                    </h5>
                                                    <QaReviewEvidence
                                                        reviews={
                                                            task.qa_reviews
                                                        }
                                                    />
                                                </div>
                                            )}

                                            {task.worktree_path && (
                                                <div className="border-border bg-muted/20 mt-4 rounded-md border p-3">
                                                    <h5 className="text-sm font-medium">
                                                        Task worktree
                                                    </h5>
                                                    <dl className="text-muted-foreground mt-2 grid gap-2 text-xs sm:grid-cols-3">
                                                        <div>
                                                            <dt>Branch</dt>
                                                            <dd className="text-foreground mt-0.5 font-mono">
                                                                {
                                                                    task.branch_name
                                                                }
                                                            </dd>
                                                        </div>
                                                        <div>
                                                            <dt>Base SHA</dt>
                                                            <dd className="text-foreground mt-0.5 font-mono break-all">
                                                                {task.base_sha}
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

                                                <div>
                                                    <h5 className="font-medium">
                                                        Implementation
                                                        specification
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 whitespace-pre-wrap">
                                                        {
                                                            task.implementation_spec
                                                        }
                                                    </p>
                                                </div>

                                                <div>
                                                    <h5 className="font-medium">
                                                        Acceptance criteria
                                                    </h5>
                                                    <ul className="text-muted-foreground mt-1 list-disc space-y-1 pl-5">
                                                        {task.acceptance_criteria.map(
                                                            (
                                                                criterion,
                                                                index,
                                                            ) => (
                                                                <li
                                                                    key={`${task.id}-acceptance-${index}`}
                                                                >
                                                                    {criterion}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </div>

                                                <div>
                                                    <h5 className="font-medium">
                                                        Verification commands
                                                    </h5>
                                                    <ul className="mt-1 space-y-2">
                                                        {task.verification_commands.map(
                                                            (
                                                                command,
                                                                index,
                                                            ) => (
                                                                <li
                                                                    key={`${task.id}-command-${index}`}
                                                                >
                                                                    <code className="bg-muted block overflow-x-auto rounded px-2 py-1.5 text-xs">
                                                                        {
                                                                            command
                                                                        }
                                                                    </code>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </div>

                                                <div>
                                                    <h5 className="font-medium">
                                                        Browser test steps
                                                    </h5>
                                                    <ol className="text-muted-foreground mt-1 list-decimal space-y-1 pl-5">
                                                        {task.browser_steps.map(
                                                            (step, index) => (
                                                                <li
                                                                    key={`${task.id}-browser-${index}`}
                                                                >
                                                                    {step}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ol>
                                                </div>
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
