import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    Bot,
    Check,
    ChevronDown,
    ClipboardList,
    Code2,
    Copy,
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    Play,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import {
    type AgentRun,
    type AgentSession,
    type CandidateReview,
    formatTimestamp,
    type HandoffRecord,
    humanize,
    ReviewStatusBadge,
    RunStatusBadge,
    serializeFindings,
    shortSha,
    StatusBadge,
    type Task,
    type WorkflowStatus,
} from '@/components/projects/tasks/task-ui';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    index as projectsIndex,
    show as showProject,
} from '@/routes/projects';
import {
    retry as retryTask,
    run as runTask,
    show as showTask,
} from '@/routes/projects/tasks';

type ProjectSummary = {
    id: number;
    title: string;
};

type SourceWorkRequest = {
    id: number;
    prompt: string;
    status: WorkflowStatus;
    outcome: string | null;
    summary: string | null;
    failure_reason: string | null;
    source_type: string;
    source_url: string | null;
};

type Dependency = {
    id: number;
    title: string;
    status: WorkflowStatus;
} | null;

type TaskPageProps = {
    project: ProjectSummary;
    workRequest: SourceWorkRequest;
    dependency: Dependency;
    task: Task;
};

type WorkflowItem =
    | {
          kind: 'run';
          key: string;
          timestamp: string | null;
          session: AgentSession;
          run: AgentRun;
      }
    | {
          kind: 'handoff';
          key: string;
          timestamp: string | null;
          handoff: HandoffRecord;
      }
    | {
          kind: 'review';
          key: string;
          timestamp: string | null;
          review: CandidateReview;
      };

/**
 * Convert a persisted timestamp into a deterministic sortable numeric value.
 */
function timestampValue(value: string | null): number {
    if (!value) {
        return Number.MAX_SAFE_INTEGER;
    }

    const timestamp = Date.parse(value);

    return Number.isNaN(timestamp)
        ? Number.MAX_SAFE_INTEGER
        : timestamp;
}

/**
 * Build one chronological presentation stream from durable runs, handoffs, and QA reviews.
 */
function buildWorkflowItems(task: Task): WorkflowItem[] {
    const runs: WorkflowItem[] = task.agent_sessions.flatMap((session) =>
        session.runs.map((run) => ({
            kind: 'run' as const,
            key: `run-${run.id}`,
            timestamp: run.started_at ?? run.finished_at,
            session,
            run,
        })),
    );

    const handoffs: WorkflowItem[] = task.handoffs.map((handoff) => ({
        kind: 'handoff' as const,
        key: `handoff-${handoff.id}`,
        timestamp: handoff.dispatched_at,
        handoff,
    }));

    const reviews: WorkflowItem[] = task.candidate_reviews.map(
        (review, index) => ({
            kind: 'review' as const,
            key: `review-${review.candidate_tree_sha ?? 'none'}-${review.created_at ?? index}`,
            timestamp: review.created_at,
            review,
        }),
    );

    const typeRank: Record<WorkflowItem['kind'], number> = {
        run: 0,
        review: 1,
        handoff: 2,
    };

    return [...runs, ...reviews, ...handoffs].sort((left, right) => {
        const timestampDifference =
            timestampValue(left.timestamp) - timestampValue(right.timestamp);

        if (timestampDifference !== 0) {
            return timestampDifference;
        }

        const typeDifference =
            typeRank[left.kind] - typeRank[right.kind];

        if (typeDifference !== 0) {
            return typeDifference;
        }

        return left.key.localeCompare(right.key);
    });
}

/**
 * Render an icon representing the durable Agent role without relying on color.
 */
function AgentRoleIcon({ role }: { role: string }) {
    const normalizedRole = role.toLowerCase();

    if (normalizedRole.includes('project_manager')) {
        return <ClipboardList className="size-4" aria-hidden="true" />;
    }

    if (normalizedRole.includes('coder')) {
        return <Code2 className="size-4" aria-hidden="true" />;
    }

    if (normalizedRole.includes('qa')) {
        return <ShieldCheck className="size-4" aria-hidden="true" />;
    }

    return <Bot className="size-4" aria-hidden="true" />;
}

/**
 * Render a compact label/value pair for Task inspection metadata.
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
        <div className="grid min-w-0 grid-cols-[7.5rem_minmax(0,1fr)] gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={
                    mono
                        ? 'min-w-0 break-all font-mono'
                        : 'min-w-0 break-words'
                }
            >
                {children}
            </dd>
        </div>
    );
}

/**
 * Render one visually restrained Task Details section separated from adjacent groups.
 */
function DetailsSection({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="grid gap-3">
            <h3 className="text-sm font-semibold">{title}</h3>
            {children}
        </section>
    );
}

/**
 * Copy a full persisted identifier with accessible visual success feedback.
 */
function CopyValueButton({
    value,
    label,
}: {
    value: string;
    label: string;
}) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === value;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 shrink-0"
                    aria-label={copied ? `${label} copied` : `Copy ${label}`}
                    onClick={() => void copy(value)}
                >
                    {copied ? (
                        <Check className="size-3.5" aria-hidden="true" />
                    ) : (
                        <Copy className="size-3.5" aria-hidden="true" />
                    )}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {copied ? 'Copied' : `Copy ${label}`}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Render one Agent invocation as an accessible progressive-disclosure timeline row.
 */
function AgentRunTimelineRow({
    session,
    run,
    handoffs,
}: {
    session: AgentSession;
    run: AgentRun;
    handoffs: HandoffRecord[];
}) {
    const roleHandoffs = handoffs.filter(
        (handoff) => handoff.from_role === session.agent.role,
    );

    const defaultOpen =
        run.status === 'failed'
        || run.reconciliation_status === 'recoverable'
        || run.reconciliation_status === 'terminal'
        || run.failure_class !== null;

    return (
        <Collapsible defaultOpen={defaultOpen}>
            <div className="border-border bg-card overflow-hidden rounded-lg border">
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="group focus-visible:ring-ring flex w-full min-w-0 items-start gap-3 px-4 py-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-inset"
                    >
                        <div className="bg-muted mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full">
                            <AgentRoleIcon role={session.agent.role} />
                        </div>

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {session.agent.name}
                                </span>
                                <span className="text-muted-foreground text-xs capitalize">
                                    {humanize(session.agent.role)}
                                </span>
                                <Badge variant="outline">
                                    Run #{run.attempt}
                                </Badge>
                            </div>

                            <p className="mt-1 text-sm font-medium">
                                {humanize(run.purpose)}
                            </p>

                            {run.output_summary && (
                                <p className="text-muted-foreground mt-1 line-clamp-2 text-sm">
                                    {run.output_summary}
                                </p>
                            )}
                        </div>

                        <div className="flex shrink-0 flex-col items-end gap-2">
                            <span className="text-muted-foreground text-xs">
                                {formatTimestamp(
                                    run.finished_at ?? run.started_at,
                                )}
                            </span>
                            <div className="flex items-center gap-2">
                                <RunStatusBadge status={run.status} />
                                <ChevronDown
                                    className="text-muted-foreground size-4 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none"
                                    aria-hidden="true"
                                />
                            </div>
                        </div>
                    </button>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <div className="border-border grid gap-4 border-t px-4 py-4">
                        <div className="grid gap-2">
                            <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                                Output summary
                            </p>
                            <p className="text-sm whitespace-pre-wrap">
                                {run.output_summary
                                    ?? 'No output summary was recorded.'}
                            </p>
                        </div>

                        <dl className="grid gap-3 md:grid-cols-2">
                            <MetadataField label="Context mode">
                                <span className="capitalize">
                                    {humanize(run.context_mode)}
                                </span>
                            </MetadataField>

                            <MetadataField label="Continuity">
                                {session.has_provider_continuity
                                    ? 'Provider resume available'
                                    : 'Logical continuity only'}
                            </MetadataField>

                            <MetadataField label="Started">
                                {formatTimestamp(run.started_at)}
                            </MetadataField>

                            <MetadataField label="Finished">
                                {run.finished_at
                                    ? formatTimestamp(run.finished_at)
                                    : 'Still running'}
                            </MetadataField>

                            <MetadataField label="Exit code">
                                {run.exit_code ?? 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Harness">
                                {run.harness ?? 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Model">
                                {run.model ?? 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Reconciliation">
                                {run.reconciliation_status
                                    ? humanize(run.reconciliation_status)
                                    : 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Failure class">
                                {run.failure_class
                                    ? humanize(run.failure_class)
                                    : 'None recorded'}
                            </MetadataField>
                        </dl>

                        {(run.reconciliation_status || run.failure_class) && (
                            <div className="flex flex-wrap gap-2">
                                {run.reconciliation_status && (
                                    <Badge
                                        variant={
                                            run.reconciliation_status
                                            === 'terminal'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        Reconciliation:{' '}
                                        {humanize(run.reconciliation_status)}
                                    </Badge>
                                )}

                                {run.failure_class && (
                                    <Badge
                                        variant={
                                            run.failure_class
                                            === 'terminal_blocked'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {humanize(run.failure_class)}
                                    </Badge>
                                )}
                            </div>
                        )}

                        <div>
                            <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                                Context sources
                            </p>

                            {run.context_sources.length === 0 ? (
                                <p className="text-muted-foreground mt-2 text-sm">
                                    No context sources were recorded.
                                </p>
                            ) : (
                                <div className="mt-2 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                                    {run.context_sources.map(
                                        (source, index) => (
                                            <Badge
                                                key={`${run.id}-source-${index}`}
                                                variant="outline"
                                            >
                                                {source.label}
                                                <span className="text-muted-foreground">
                                                    · {humanize(source.type)}
                                                </span>
                                            </Badge>
                                        ),
                                    )}
                                </div>
                            )}
                        </div>

                        {roleHandoffs.length > 0 && (
                            <details className="border-border rounded-md border">
                                <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                    Handoff evidence from this role
                                </summary>
                                <div className="border-border grid gap-2 border-t p-3">
                                    {roleHandoffs.map((handoff) => (
                                        <div
                                            key={`${run.id}-role-handoff-${handoff.id}`}
                                            className="text-sm"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="capitalize">
                                                    {humanize(
                                                        handoff.from_role
                                                            ?? 'unknown',
                                                    )}{' '}
                                                    →{' '}
                                                    {humanize(
                                                        handoff.to_role
                                                            ?? 'unknown',
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {formatTimestamp(
                                                        handoff.dispatched_at,
                                                    )}
                                                </span>
                                            </div>
                                            <p className="text-muted-foreground mt-1 capitalize">
                                                {humanize(handoff.reason)}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </details>
                        )}

                        <details className="border-border rounded-md border">
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
                </CollapsibleContent>
            </div>
        </Collapsible>
    );
}

/**
 * Render one durable role handoff as a chronological workflow event.
 */
function HandoffTimelineRow({
    handoff,
}: {
    handoff: HandoffRecord;
}) {
    return (
        <div className="border-border bg-card rounded-lg border px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <Activity
                        className="text-muted-foreground size-4"
                        aria-hidden="true"
                    />
                    <span className="text-sm font-medium">
                        Agent handoff
                    </span>
                </div>
                <span className="text-muted-foreground text-xs">
                    {formatTimestamp(handoff.dispatched_at)}
                </span>
            </div>

            <p className="mt-2 text-sm capitalize">
                {humanize(handoff.from_role ?? 'unknown')} →{' '}
                {humanize(handoff.to_role ?? 'unknown')}
            </p>
            <p className="text-muted-foreground mt-1 text-sm capitalize">
                {humanize(handoff.reason)}
            </p>
        </div>
    );
}

/**
 * Render one durable QA CandidateReview as a chronological workflow event.
 */
function ReviewTimelineRow({
    review,
}: {
    review: CandidateReview;
}) {
    return (
        <div className="border-border bg-card rounded-lg border px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <ShieldCheck
                        className="text-muted-foreground size-4"
                        aria-hidden="true"
                    />
                    <span className="text-sm font-medium">
                        Candidate review
                    </span>
                    <ReviewStatusBadge status={review.status} />
                </div>

                <span className="text-muted-foreground text-xs">
                    {formatTimestamp(review.created_at)}
                </span>
            </div>

            {review.summary && (
                <p className="mt-2 text-sm">{review.summary}</p>
            )}

            {review.candidate_tree_sha && (
                <p className="text-muted-foreground mt-2 font-mono text-xs break-all">
                    Candidate {review.candidate_tree_sha}
                </p>
            )}

            <details className="border-border mt-3 rounded-md border">
                <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                    Inspect QA findings
                </summary>
                <pre className="bg-muted border-border max-h-72 overflow-auto border-t p-3 text-xs whitespace-pre-wrap">
                    {serializeFindings(review.findings)}
                </pre>
            </details>
        </div>
    );
}

/**
 * Render complete Task workflow evidence as one deterministic chronological timeline.
 */
function WorkflowTimeline({ task }: { task: Task }) {
    const items = buildWorkflowItems(task);

    if (items.length === 0) {
        return (
            <div className="border-border bg-card flex min-h-56 flex-col items-center justify-center rounded-lg border p-8 text-center">
                <Activity
                    className="text-muted-foreground size-8"
                    aria-hidden="true"
                />
                <h3 className="mt-4 font-medium">
                    No workflow activity recorded yet
                </h3>
                <p className="text-muted-foreground mt-2 max-w-md text-sm">
                    Agent runs, durable handoffs, and QA review evidence will
                    appear here as the Task progresses.
                </p>
            </div>
        );
    }

    return (
        <div className="relative grid gap-3 pl-7 before:absolute before:top-3 before:bottom-3 before:left-2.5 before:w-px before:bg-border">
            {items.map((item) => (
                <div key={item.key} className="relative min-w-0">
                    <span
                        className="border-border bg-background absolute top-5 -left-[1.375rem] size-3 rounded-full border-2"
                        aria-hidden="true"
                    />

                    {item.kind === 'run' && (
                        <AgentRunTimelineRow
                            session={item.session}
                            run={item.run}
                            handoffs={task.handoffs}
                        />
                    )}

                    {item.kind === 'handoff' && (
                        <HandoffTimelineRow handoff={item.handoff} />
                    )}

                    {item.kind === 'review' && (
                        <ReviewTimelineRow review={item.review} />
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * Render only workflow actions that the existing TaskController can validly execute.
 */
function TaskActions({
    project,
    task,
}: {
    project: ProjectSummary;
    task: Task;
}) {
    const hasDispatchableHandoff = Boolean(task.last_handoff?.id);
    const waitingWithInstruction =
        task.status === 'waiting'
        && hasDispatchableHandoff
        && Boolean(task.last_handoff?.note);
    const canRun =
        hasDispatchableHandoff
        && (
            task.status === 'pending'
            || (
                task.status === 'waiting'
                && !waitingWithInstruction
            )
        );

    return (
        <div className="flex min-w-0 flex-wrap items-start justify-end gap-2">
            {canRun && (
                <Form {...runTask.form([project.id, task.id])}>
                    {({ processing }) => (
                        <Button type="submit" disabled={processing}>
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

            {task.status === 'failed' && (
                <Form {...retryTask.form([project.id, task.id])}>
                    {({ processing }) => (
                        <Button
                            type="submit"
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
            )}

            {task.pull_request_url && (
                <Button asChild>
                    <a
                        href={task.pull_request_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        View Pull Request
                        <ExternalLink aria-hidden="true" />
                    </a>
                </Button>
            )}

            {waitingWithInstruction && (
                <Form
                    {...runTask.form([project.id, task.id])}
                    className="border-border bg-muted/30 w-full rounded-lg border p-3 sm:max-w-xl"
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-2">
                            <div>
                                <p className="text-sm font-medium">
                                    Waiting for next Agent turn
                                </p>
                                <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                                    {task.last_handoff?.note}
                                </p>
                            </div>

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
                                className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring min-h-20 resize-y rounded-md border px-3 py-2 text-sm outline-none focus-visible:ring-2"
                            />

                            <InputError
                                message={errors.operator_instruction}
                            />

                            <Button
                                type="submit"
                                className="w-fit"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Continue
                            </Button>
                        </div>
                    )}
                </Form>
            )}
        </div>
    );
}

/**
 * Render the compact Task identity, objective, status, and valid workflow controls.
 */
function TaskHeader({
    project,
    task,
}: {
    project: ProjectSummary;
    task: Task;
}) {
    return (
        <Card className="gap-0 py-0">
            <CardHeader className="gap-4 p-5 md:p-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight md:text-2xl">
                                {task.title}
                            </h1>
                            <Badge variant="outline">
                                Task #{task.id}
                            </Badge>
                            <StatusBadge status={task.status} />
                        </div>

                        <p className="text-muted-foreground mt-2 max-w-4xl text-sm leading-6">
                            <span className="text-foreground font-medium">
                                Objective:
                            </span>{' '}
                            {task.objective}
                        </p>
                    </div>

                    <TaskActions project={project} task={task} />
                </div>

                {(
                    task.status === 'pending'
                    || task.status === 'waiting'
                )
                && !task.last_handoff?.id && (
                    <p className="text-muted-foreground text-xs">
                        No dispatchable durable handoff is currently recorded,
                        so no Run action is available.
                    </p>
                )}
            </CardHeader>
        </Card>
    );
}

/**
 * Return the newest CandidateReview using durable creation timestamps.
 */
function latestCandidateReview(
    reviews: CandidateReview[],
): CandidateReview | null {
    return [...reviews].sort(
        (left, right) =>
            timestampValue(right.created_at)
            - timestampValue(left.created_at),
    )[0] ?? null;
}

/**
 * Render the sticky factual Task inspector using only persisted repository data.
 */
function TaskDetails({
    task,
    dependency,
    workRequest,
}: {
    task: Task;
    dependency: Dependency;
    workRequest: SourceWorkRequest;
}) {
    const latestReview = latestCandidateReview(task.candidate_reviews);
    const latestHandoff =
        [...task.handoffs].sort(
            (left, right) => right.id - left.id,
        )[0] ?? null;

    const hasCandidateMetadata =
        task.branch_name !== null
        || task.worktree_path !== null
        || task.candidate_tree_sha !== null
        || task.commit_sha !== null
        || task.pull_request_url !== null
        || task.candidate_kind !== null
        || task.changed_files.length > 0;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-border border-b px-4 py-4">
                <h2 className="font-semibold">Task Details</h2>
            </CardHeader>

            <CardContent className="grid gap-5 p-4">
                <DetailsSection title="Overview">
                    <div>
                        <p className="text-muted-foreground text-xs">
                            Objective
                        </p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">
                            {task.objective}
                        </p>
                    </div>

                    <div>
                        <p className="text-muted-foreground text-xs">
                            Implementation specification
                        </p>
                        {task.implementation_spec ? (
                            <div className="mt-1 max-h-56 overflow-y-auto text-sm whitespace-pre-wrap">
                                {task.implementation_spec}
                            </div>
                        ) : (
                            <p className="text-muted-foreground mt-1 text-sm">
                                Not provided.
                            </p>
                        )}
                    </div>
                </DetailsSection>

                <Separator />

                <DetailsSection title="Workflow">
                    <dl className="grid gap-2.5">
                        <MetadataField label="Status">
                            <StatusBadge status={task.status} />
                        </MetadataField>

                        <MetadataField label="Outcome">
                            <span className="capitalize">
                                {task.outcome
                                    ? humanize(task.outcome)
                                    : 'Not recorded'}
                            </span>
                        </MetadataField>

                        <MetadataField label="Dependency">
                            {dependency
                                ? `Task #${dependency.id}: ${dependency.title}`
                                : task.depends_on_task_id
                                  ? `Task #${task.depends_on_task_id}`
                                  : 'None'}
                        </MetadataField>

                        <MetadataField label="Repair cycles">
                            {task.repair_cycle_count} of{' '}
                            {task.repair_cycle_limit}
                        </MetadataField>

                        <MetadataField label="Protocol recoveries">
                            {task.protocol_recovery_count}
                        </MetadataField>

                        <MetadataField label="Latest handoff">
                            {latestHandoff ? (
                                <span className="capitalize">
                                    {humanize(
                                        latestHandoff.from_role ?? 'unknown',
                                    )}{' '}
                                    →{' '}
                                    {humanize(
                                        latestHandoff.to_role ?? 'unknown',
                                    )}
                                </span>
                            ) : task.last_handoff?.to_role ? (
                                <span className="capitalize">
                                    To{' '}
                                    {humanize(task.last_handoff.to_role)}
                                </span>
                            ) : (
                                'None'
                            )}
                        </MetadataField>

                        {latestHandoff && (
                            <MetadataField label="Handoff reason">
                                <span className="capitalize">
                                    {humanize(latestHandoff.reason)}
                                </span>
                            </MetadataField>
                        )}

                        {(task.blocked_reason || task.outcome === 'blocked') && (
                            <MetadataField label="Blocked reason">
                                <span className="text-destructive whitespace-pre-wrap">
                                    {task.blocked_reason
                                        || 'Blocked without an additional recorded reason.'}
                                </span>
                            </MetadataField>
                        )}
                    </dl>
                </DetailsSection>

                <Separator />

                <DetailsSection title="Candidate & Repository">
                    {!hasCandidateMetadata ? (
                        <p className="text-muted-foreground text-sm">
                            No candidate or Task worktree metadata has been
                            recorded yet.
                        </p>
                    ) : (
                        <dl className="grid gap-2.5">
                            <MetadataField label="Branch" mono>
                                {task.branch_name ?? 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Candidate kind">
                                <span className="capitalize">
                                    {task.candidate_kind
                                        ? humanize(task.candidate_kind)
                                        : 'Not recorded'}
                                </span>
                            </MetadataField>

                            <MetadataField label="Candidate tree">
                                {task.candidate_tree_sha ? (
                                    <div className="flex min-w-0 items-center gap-1">
                                        <span
                                            className="min-w-0 break-all font-mono"
                                            title={task.candidate_tree_sha}
                                        >
                                            {shortSha(
                                                task.candidate_tree_sha,
                                            )}
                                        </span>
                                        <CopyValueButton
                                            value={task.candidate_tree_sha}
                                            label="candidate tree SHA"
                                        />
                                    </div>
                                ) : (
                                    'Not recorded'
                                )}
                            </MetadataField>

                            <MetadataField label="Commit SHA">
                                {task.commit_sha ? (
                                    <div className="flex min-w-0 items-center gap-1">
                                        <span
                                            className="min-w-0 break-all font-mono"
                                            title={task.commit_sha}
                                        >
                                            {shortSha(task.commit_sha)}
                                        </span>
                                        <CopyValueButton
                                            value={task.commit_sha}
                                            label="commit SHA"
                                        />
                                    </div>
                                ) : (
                                    'Not recorded'
                                )}
                            </MetadataField>

                            <MetadataField label="Worktree" mono>
                                {task.worktree_path ?? 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Changed files">
                                {task.changed_files.length}
                            </MetadataField>
                        </dl>
                    )}

                    {task.changed_files.length > 0 && (
                        <details className="border-border rounded-md border">
                            <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                Inspect changed files
                            </summary>
                            <ul className="border-border grid max-h-56 gap-1 overflow-y-auto border-t p-3">
                                {task.changed_files.map((file) => (
                                    <li
                                        key={`${task.id}-${file}`}
                                        className="bg-muted rounded-md px-2 py-1 font-mono text-xs break-all"
                                    >
                                        {file}
                                    </li>
                                ))}
                            </ul>
                        </details>
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
                                View Pull Request
                                <ExternalLink aria-hidden="true" />
                            </a>
                        </Button>
                    )}
                </DetailsSection>

                <Separator />

                <DetailsSection title="QA Review">
                    {!latestReview ? (
                        <p className="text-muted-foreground text-sm">
                            No QA review has been recorded for this Task yet.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <ReviewStatusBadge
                                    status={latestReview.status}
                                />
                                <span className="text-muted-foreground text-xs">
                                    {formatTimestamp(
                                        latestReview.created_at,
                                    )}
                                </span>
                            </div>

                            {latestReview.summary && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {latestReview.summary}
                                </p>
                            )}

                            {latestReview.candidate_tree_sha && (
                                <div>
                                    <p className="text-muted-foreground text-xs">
                                        Reviewed candidate
                                    </p>
                                    <div className="mt-1 flex items-center gap-1">
                                        <span
                                            className="font-mono text-xs"
                                            title={
                                                latestReview.candidate_tree_sha
                                            }
                                        >
                                            {shortSha(
                                                latestReview.candidate_tree_sha,
                                            )}
                                        </span>
                                        <CopyValueButton
                                            value={
                                                latestReview.candidate_tree_sha
                                            }
                                            label="reviewed candidate SHA"
                                        />
                                    </div>
                                </div>
                            )}

                            <details className="border-border rounded-md border">
                                <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                                    Latest findings
                                </summary>
                                <pre className="bg-muted border-border max-h-64 overflow-auto border-t p-3 text-xs whitespace-pre-wrap">
                                    {serializeFindings(
                                        latestReview.findings,
                                    )}
                                </pre>
                            </details>
                        </div>
                    )}
                </DetailsSection>

                <Separator />

                <DetailsSection title="Source Work Request">
                    <dl className="grid gap-2.5">
                        <MetadataField label="ID">
                            Work Request #{workRequest.id}
                        </MetadataField>

                        <MetadataField label="Source">
                            <span className="capitalize">
                                {humanize(workRequest.source_type)}
                            </span>
                        </MetadataField>

                        <MetadataField label="Status">
                            <StatusBadge status={workRequest.status} />
                        </MetadataField>

                        <MetadataField label="Outcome">
                            <span className="capitalize">
                                {workRequest.outcome
                                    ? humanize(workRequest.outcome)
                                    : 'Not recorded'}
                            </span>
                        </MetadataField>
                    </dl>

                    <div>
                        <p className="text-muted-foreground text-xs">
                            Prompt
                        </p>
                        <div className="mt-1 max-h-48 overflow-y-auto text-sm whitespace-pre-wrap">
                            {workRequest.prompt}
                        </div>
                    </div>

                    {workRequest.summary && (
                        <div>
                            <p className="text-muted-foreground text-xs">
                                Summary
                            </p>
                            <p className="mt-1 text-sm whitespace-pre-wrap">
                                {workRequest.summary}
                            </p>
                        </div>
                    )}

                    {workRequest.failure_reason && (
                        <div className="border-destructive/30 bg-destructive/5 rounded-md border p-3">
                            <p className="text-destructive text-xs font-medium">
                                Failure information
                            </p>
                            <p className="text-muted-foreground mt-1 text-sm whitespace-pre-wrap">
                                {workRequest.failure_reason}
                            </p>
                        </div>
                    )}

                    {workRequest.source_url && (
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="w-fit"
                        >
                            <a
                                href={workRequest.source_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Open source
                                <ExternalLink aria-hidden="true" />
                            </a>
                        </Button>
                    )}
                </DetailsSection>
            </CardContent>
        </Card>
    );
}

/**
 * Render the dedicated responsive Task inspection workspace.
 */
export default function TaskShow({
    project,
    workRequest,
    dependency,
    task,
}: TaskPageProps) {
    return (
        <>
            <Head title={`Task #${task.id} · ${task.title}`} />

            <main className="mx-auto flex w-full max-w-[1800px] flex-col gap-4 p-4 md:p-6 xl:p-8">
                <TaskHeader project={project} task={task} />

                <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,7fr)_minmax(20rem,3fr)]">
                    <section
                        className="min-w-0"
                        aria-labelledby="workflow-history-heading"
                    >
                        <Card className="gap-0 py-0">
                            <CardHeader className="border-border border-b px-4 py-4">
                                <div>
                                    <h2
                                        id="workflow-history-heading"
                                        className="font-semibold"
                                    >
                                        Agent workflow history
                                    </h2>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        Chronological durable Agent runs,
                                        handoffs, and QA evidence.
                                    </p>
                                </div>
                            </CardHeader>

                            <CardContent className="p-4">
                                <WorkflowTimeline task={task} />
                            </CardContent>
                        </Card>
                    </section>

                    <aside className="min-w-0 xl:sticky xl:top-20 xl:max-h-[calc(100vh-6rem)] xl:self-start xl:overflow-y-auto xl:overscroll-contain">
                        <TaskDetails
                            task={task}
                            dependency={dependency}
                            workRequest={workRequest}
                        />
                    </aside>
                </div>
            </main>
        </>
    );
}

/**
 * Supply dynamic breadcrumbs to the existing persistent AppLayout.
 */
TaskShow.layout = (props: TaskPageProps) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: projectsIndex(),
        },
        {
            title: props.project.title,
            href: showProject(props.project.id),
        },
        {
            title: `Task #${props.task.id}`,
            href: showTask([props.project.id, props.task.id]),
        },
    ],
});
