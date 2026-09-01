import { Form, Head } from '@inertiajs/react';
import {
    Activity,
    Bot,
    Check,
    ChevronDown,
    ClipboardList,
    Code2,
    Copy,
    ExternalLink,
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
import { Card, CardContent, CardHeader } from '@/components/ui/card';
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
import { index as projectsIndex, show as showProject } from '@/routes/projects';
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

type RunWorkflowItem = {
    kind: 'run';
    key: string;
    timestamp: string | null;
    session: AgentSession;
    run: AgentRun;
    handoffs: HandoffRecord[];
    reviews: CandidateReview[];
};

type WorkflowItem =
    | RunWorkflowItem
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

    return Number.isNaN(timestamp) ? Number.MAX_SAFE_INTEGER : timestamp;
}

/**
 * Return a nullable timestamp for proximity calculations without treating
 * missing or invalid timestamps as meaningful workflow evidence.
 */
function sortableTimestamp(value: string | null): number | null {
    const timestamp = timestampValue(value);

    return timestamp === Number.MAX_SAFE_INTEGER ? null : timestamp;
}

/**
 * Normalize persisted Agent role names before comparing workflow evidence.
 */
function normalizeRole(value: string | null): string {
    return (value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');
}

/**
 * Determine whether a persisted Agent role represents QA activity.
 */
function isQaRole(role: string): boolean {
    const normalizedRole = normalizeRole(role);

    return (
        normalizedRole === 'qa' ||
        normalizedRole.includes('qa_') ||
        normalizedRole.includes('_qa') ||
        normalizedRole.includes('quality_assurance')
    );
}

/**
 * Return the most useful completion-side timestamp for associating evidence
 * created immediately after an Agent invocation.
 */
function runEvidenceTimestamp(run: AgentRun): number | null {
    return sortableTimestamp(run.finished_at ?? run.started_at);
}

/**
 * Find the most meaningful originating Agent Run for one durable handoff.
 */
function findHandoffRunIndex(
    runs: RunWorkflowItem[],
    handoff: HandoffRecord,
): number | null {
    const fromRole = normalizeRole(handoff.from_role);

    if (!fromRole) {
        return null;
    }

    const candidates = runs
        .map((item, index) => ({
            item,
            index,
            timestamp: runEvidenceTimestamp(item.run),
        }))
        .filter(
            ({ item }) => normalizeRole(item.session.agent.role) === fromRole,
        );

    if (candidates.length === 0) {
        return null;
    }

    const handoffTimestamp = sortableTimestamp(handoff.dispatched_at);

    if (handoffTimestamp !== null) {
        const priorCandidates = candidates
            .filter(
                ({ timestamp }) =>
                    timestamp !== null && timestamp <= handoffTimestamp,
            )
            .sort(
                (left, right) => (right.timestamp ?? 0) - (left.timestamp ?? 0),
            );

        if (priorCandidates.length > 0) {
            return priorCandidates[0].index;
        }

        const closestCandidates = candidates
            .filter(({ timestamp }) => timestamp !== null)
            .sort(
                (left, right) =>
                    Math.abs((left.timestamp ?? 0) - handoffTimestamp) -
                    Math.abs((right.timestamp ?? 0) - handoffTimestamp),
            );

        if (closestCandidates.length > 0) {
            return closestCandidates[0].index;
        }
    }

    return (
        [...candidates].sort(
            (left, right) => right.item.run.id - left.item.run.id,
        )[0]?.index ?? null
    );
}

/**
 * Find the QA Agent Run that most meaningfully corresponds to a durable
 * CandidateReview without inventing a persisted relationship.
 */
function findReviewRunIndex(
    runs: RunWorkflowItem[],
    review: CandidateReview,
): number | null {
    const candidates = runs
        .map((item, index) => ({
            item,
            index,
            timestamp: runEvidenceTimestamp(item.run),
        }))
        .filter(({ item }) => isQaRole(item.session.agent.role));

    if (candidates.length === 0) {
        return null;
    }

    const reviewTimestamp = sortableTimestamp(review.created_at);

    if (reviewTimestamp !== null) {
        const priorCandidates = candidates
            .filter(
                ({ timestamp }) =>
                    timestamp !== null && timestamp <= reviewTimestamp,
            )
            .sort(
                (left, right) => (right.timestamp ?? 0) - (left.timestamp ?? 0),
            );

        if (priorCandidates.length > 0) {
            return priorCandidates[0].index;
        }

        const closestCandidates = candidates
            .filter(({ timestamp }) => timestamp !== null)
            .sort(
                (left, right) =>
                    Math.abs((left.timestamp ?? 0) - reviewTimestamp) -
                    Math.abs((right.timestamp ?? 0) - reviewTimestamp),
            );

        if (closestCandidates.length > 0) {
            return closestCandidates[0].index;
        }
    }

    return (
        [...candidates].sort(
            (left, right) => right.item.run.id - left.item.run.id,
        )[0]?.index ?? null
    );
}

/**
 * Build the workflow presentation around Agent Runs, enriching those turns
 * with associated handoffs and CandidateReviews while preserving standalone
 * durable evidence when no meaningful Agent Run association exists.
 */
function buildWorkflowItems(task: Task): WorkflowItem[] {
    const runs: RunWorkflowItem[] = task.agent_sessions.flatMap((session) =>
        session.runs.map((run) => ({
            kind: 'run' as const,
            key: `run-${run.id}`,
            timestamp: run.started_at ?? run.finished_at,
            session,
            run,
            handoffs: [],
            reviews: [],
        })),
    );

    const standaloneHandoffs: WorkflowItem[] = [];
    const standaloneReviews: WorkflowItem[] = [];

    task.handoffs.forEach((handoff) => {
        const runIndex = findHandoffRunIndex(runs, handoff);

        if (runIndex === null) {
            standaloneHandoffs.push({
                kind: 'handoff',
                key: `handoff-${handoff.id}`,
                timestamp: handoff.dispatched_at,
                handoff,
            });

            return;
        }

        runs[runIndex].handoffs.push(handoff);
    });

    task.candidate_reviews.forEach((review, index) => {
        const runIndex = findReviewRunIndex(runs, review);

        if (runIndex === null) {
            standaloneReviews.push({
                kind: 'review',
                key: `review-${review.candidate_tree_sha ?? 'none'}-${review.created_at ?? index}-${index}`,
                timestamp: review.created_at,
                review,
            });

            return;
        }

        runs[runIndex].reviews.push(review);
    });

    const typeRank: Record<WorkflowItem['kind'], number> = {
        run: 0,
        review: 1,
        handoff: 2,
    };

    return [...runs, ...standaloneReviews, ...standaloneHandoffs].sort(
        (left, right) => {
            const timestampDifference =
                timestampValue(left.timestamp) -
                timestampValue(right.timestamp);

            if (timestampDifference !== 0) {
                return timestampDifference;
            }

            const typeDifference = typeRank[left.kind] - typeRank[right.kind];

            if (typeDifference !== 0) {
                return typeDifference;
            }

            return left.key.localeCompare(right.key);
        },
    );
}

/**
 * Render an icon representing the durable Agent role without relying on
 * screenshot-only identities or fabricated avatar assets.
 */
function AgentRoleIcon({ role }: { role: string }) {
    const normalizedRole = normalizeRole(role);

    if (normalizedRole.includes('project_manager')) {
        return <ClipboardList className="size-4" aria-hidden="true" />;
    }

    if (normalizedRole.includes('coder')) {
        return <Code2 className="size-4" aria-hidden="true" />;
    }

    if (isQaRole(normalizedRole)) {
        return <ShieldCheck className="size-4" aria-hidden="true" />;
    }

    return <Bot className="size-4" aria-hidden="true" />;
}

/**
 * Render a compact aligned label/value pair for the narrow Task inspector and
 * expanded Agent evidence metadata.
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
        <div className="grid min-w-0 grid-cols-[6.5rem_minmax(0,1fr)] items-start gap-x-3 text-sm">
            <dt className="text-muted-foreground leading-5">{label}</dt>
            <dd
                className={
                    mono
                        ? 'min-w-0 font-mono text-xs leading-5 break-all'
                        : 'min-w-0 leading-5 break-words'
                }
            >
                {children}
            </dd>
        </div>
    );
}

/**
 * Render one internally separated Task Details section without introducing
 * nested card surfaces.
 */
function DetailsSection({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="grid gap-3 px-4 py-4">
            <h3 className="text-sm font-semibold">{title}</h3>
            {children}
        </section>
    );
}

/**
 * Copy a complete persisted identifier while displaying accessible success
 * feedback and leaving the visible value compact.
 */
function CopyValueButton({ value, label }: { value: string; label: string }) {
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
 * Render a shortened Git identifier with a copy control that preserves access
 * to the complete persisted SHA.
 */
function CopyableSha({
    value,
    label,
}: {
    value: string | null;
    label: string;
}) {
    if (!value) {
        return <span className="text-muted-foreground">Not recorded</span>;
    }

    return (
        <div className="flex min-w-0 items-center gap-1">
            <span className="min-w-0 font-mono text-xs break-all" title={value}>
                {shortSha(value)}
            </span>
            <CopyValueButton value={value} label={label} />
        </div>
    );
}

/**
 * Format a durable role-to-role handoff using only recorded role values.
 */
function formatRoleRoute(
    fromRole: string | null,
    toRole: string | null,
): string {
    return `${humanize(fromRole ?? 'unknown')} → ${humanize(toRole ?? 'unknown')}`;
}

/**
 * Render durable handoff evidence inside the Agent turn that most meaningfully
 * originated it.
 */
function HandoffEvidence({ handoffs }: { handoffs: HandoffRecord[] }) {
    if (handoffs.length === 0) {
        return null;
    }

    return (
        <details className="border-border rounded-md border">
            <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                Handoff evidence
            </summary>
            <div className="border-border grid gap-3 border-t p-3">
                {handoffs.map((handoff) => (
                    <div key={handoff.id} className="grid gap-1 text-sm">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <span className="font-medium capitalize">
                                {formatRoleRoute(
                                    handoff.from_role,
                                    handoff.to_role,
                                )}
                            </span>
                            <span className="text-muted-foreground shrink-0 text-xs">
                                {formatTimestamp(handoff.dispatched_at)}
                            </span>
                        </div>
                        <span className="text-muted-foreground capitalize">
                            {humanize(handoff.reason)}
                        </span>
                    </div>
                ))}
            </div>
        </details>
    );
}

/**
 * Render durable CandidateReview evidence inside its associated QA Agent turn
 * so review findings do not duplicate the primary workflow chronology.
 */
function QaReviewEvidence({ reviews }: { reviews: CandidateReview[] }) {
    if (reviews.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3">
            {reviews.map((review, index) => (
                <div
                    key={`${review.candidate_tree_sha ?? 'none'}-${review.created_at ?? index}-${index}`}
                    className="border-border bg-muted/20 grid gap-3 rounded-md border p-3"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-medium">
                                QA review
                            </span>
                            <ReviewStatusBadge status={review.status} />
                        </div>
                        <span className="text-muted-foreground text-xs">
                            {formatTimestamp(review.created_at)}
                        </span>
                    </div>

                    <dl className="grid gap-2">
                        <MetadataField label="Candidate">
                            <CopyableSha
                                value={review.candidate_tree_sha}
                                label="reviewed candidate SHA"
                            />
                        </MetadataField>
                    </dl>

                    {review.summary && (
                        <p className="text-sm leading-6 break-words whitespace-pre-wrap">
                            {review.summary}
                        </p>
                    )}

                    <details className="border-border bg-background rounded-md border">
                        <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                            Inspect QA findings
                        </summary>
                        <pre className="bg-muted/50 border-border max-h-72 overflow-y-auto border-t p-3 text-xs leading-5 break-all whitespace-pre-wrap">
                            {serializeFindings(review.findings)}
                        </pre>
                    </details>
                </div>
            ))}
        </div>
    );
}

/**
 * Render one Agent invocation as the dominant accessible workflow timeline row.
 */
function AgentRunTimelineRow({
    session,
    run,
    handoffs,
    reviews,
}: {
    session: AgentSession;
    run: AgentRun;
    handoffs: HandoffRecord[];
    reviews: CandidateReview[];
}) {
    const latestHandoff = handoffs[handoffs.length - 1] ?? null;
    const latestReview = reviews[reviews.length - 1] ?? null;
    const hasReviewNeedingAttention = reviews.some(
        (review) => review.status !== 'approved',
    );

    const defaultOpen =
        run.status === 'failed' ||
        run.reconciliation_status === 'recoverable' ||
        run.reconciliation_status === 'terminal' ||
        run.failure_class !== null ||
        hasReviewNeedingAttention;

    return (
        <Collapsible defaultOpen={defaultOpen}>
            <div className="border-border bg-card overflow-hidden rounded-lg border shadow-none">
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="group focus-visible:ring-ring flex w-full min-w-0 items-start gap-3 px-4 py-3.5 text-left outline-none focus-visible:ring-2 focus-visible:ring-inset"
                    >
                        <div className="border-border bg-muted/60 mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full border">
                            <AgentRoleIcon role={session.agent.role} />
                        </div>

                        <div className="min-w-0 flex-1 xl:grid xl:grid-cols-[8.75rem_minmax(0,1fr)] xl:gap-4">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate text-sm font-semibold">
                                        {session.agent.name}
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className="h-5 px-1.5 text-[11px]"
                                    >
                                        Run #{run.attempt}
                                    </Badge>
                                </div>
                                <p className="text-muted-foreground mt-0.5 text-xs capitalize">
                                    {humanize(session.agent.role)}
                                </p>
                            </div>

                            <div className="mt-2 min-w-0 xl:mt-0">
                                <p className="text-sm leading-5 font-medium capitalize">
                                    {humanize(run.purpose)}
                                </p>

                                {run.output_summary && (
                                    <p className="text-muted-foreground mt-1 line-clamp-2 text-sm leading-5">
                                        {run.output_summary}
                                    </p>
                                )}

                                {latestReview && (
                                    <div className="mt-2 flex min-w-0 flex-wrap items-center gap-2">
                                        <ReviewStatusBadge
                                            status={latestReview.status}
                                        />
                                        {latestReview.candidate_tree_sha && (
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {shortSha(
                                                    latestReview.candidate_tree_sha,
                                                )}
                                            </span>
                                        )}
                                        {latestReview.summary && (
                                            <span className="text-muted-foreground min-w-0 flex-1 truncate text-xs">
                                                {latestReview.summary}
                                            </span>
                                        )}
                                    </div>
                                )}

                                {latestHandoff && (
                                    <p className="text-muted-foreground mt-2 line-clamp-1 text-xs">
                                        <span className="font-medium">
                                            Handoff:
                                        </span>{' '}
                                        <span className="capitalize">
                                            {formatRoleRoute(
                                                latestHandoff.from_role,
                                                latestHandoff.to_role,
                                            )}
                                        </span>
                                        {' · '}
                                        <span className="capitalize">
                                            {humanize(latestHandoff.reason)}
                                        </span>
                                        {handoffs.length > 1
                                            ? ` · +${handoffs.length - 1} more`
                                            : ''}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex shrink-0 flex-col items-end gap-2">
                            <span className="text-muted-foreground hidden max-w-28 text-right text-xs sm:block">
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
                        <div className="grid gap-1.5">
                            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                                Output summary
                            </p>
                            <p className="text-sm leading-6 break-words whitespace-pre-wrap">
                                {run.output_summary ??
                                    'No output summary was recorded.'}
                            </p>
                        </div>

                        <dl className="grid gap-x-6 gap-y-3 xl:grid-cols-2">
                            <MetadataField label="Submitted mode">
                                <span className="capitalize">
                                    {humanize(run.context_mode)}
                                </span>
                            </MetadataField>

                            <MetadataField label="Continuity">
                                {session.has_provider_continuity
                                    ? 'Provider resume available'
                                    : 'Logical continuity only'}
                            </MetadataField>

                            <MetadataField label="Context sources">
                                {run.context_sources.length}
                            </MetadataField>

                            <MetadataField label="Harness">
                                {run.harness ?? 'Not recorded'}
                            </MetadataField>

                            <MetadataField label="Model">
                                {run.model ?? 'Not recorded'}
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

                            <MetadataField label="Reconciliation">
                                <span className="capitalize">
                                    {run.reconciliation_status
                                        ? humanize(run.reconciliation_status)
                                        : 'Not recorded'}
                                </span>
                            </MetadataField>

                            <MetadataField label="Failure class">
                                <span className="capitalize">
                                    {run.failure_class
                                        ? humanize(run.failure_class)
                                        : 'None recorded'}
                                </span>
                            </MetadataField>
                        </dl>

                        {run.context_sources.length > 0 && (
                            <details className="border-border rounded-md border">
                                <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                                    Inspect context sources
                                </summary>
                                <div className="border-border flex max-h-40 flex-wrap gap-2 overflow-y-auto border-t p-3">
                                    {run.context_sources.map(
                                        (source, index) => (
                                            <Badge
                                                key={`${run.id}-source-${index}`}
                                                variant="outline"
                                                className="max-w-full whitespace-normal"
                                            >
                                                <span className="break-words">
                                                    {source.label}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    · {humanize(source.type)}
                                                </span>
                                            </Badge>
                                        ),
                                    )}
                                </div>
                            </details>
                        )}

                        <HandoffEvidence handoffs={handoffs} />

                        <QaReviewEvidence reviews={reviews} />

                        <details className="border-border rounded-md border">
                            <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                                Inspect submitted input
                            </summary>
                            <div className="border-border border-t p-3">
                                <pre className="bg-muted max-h-96 overflow-y-auto rounded-md p-3 text-xs leading-5 break-all whitespace-pre-wrap">
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
 * Render a lower-weight standalone durable handoff only when no originating
 * Agent Run can be meaningfully associated with it.
 */
function StandaloneHandoffTimelineRow({ handoff }: { handoff: HandoffRecord }) {
    return (
        <div className="border-border bg-muted/20 rounded-md border px-4 py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <Activity
                        className="text-muted-foreground size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span className="text-sm font-medium">Agent handoff</span>
                </div>
                <span className="text-muted-foreground text-xs">
                    {formatTimestamp(handoff.dispatched_at)}
                </span>
            </div>

            <p className="mt-1.5 text-sm capitalize">
                {formatRoleRoute(handoff.from_role, handoff.to_role)}
            </p>
            <p className="text-muted-foreground mt-0.5 text-xs capitalize">
                {humanize(handoff.reason)}
            </p>
        </div>
    );
}

/**
 * Render a lower-weight standalone CandidateReview only when no QA Agent Run
 * can be meaningfully associated with the review evidence.
 */
function StandaloneReviewTimelineRow({ review }: { review: CandidateReview }) {
    return (
        <div className="border-border bg-muted/20 rounded-md border px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <ShieldCheck
                        className="text-muted-foreground size-4 shrink-0"
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
                <p className="mt-2 text-sm leading-5 break-words whitespace-pre-wrap">
                    {review.summary}
                </p>
            )}

            {review.candidate_tree_sha && (
                <div className="mt-2 flex items-center gap-1">
                    <span className="text-muted-foreground text-xs">
                        Candidate
                    </span>
                    <CopyableSha
                        value={review.candidate_tree_sha}
                        label="reviewed candidate SHA"
                    />
                </div>
            )}

            <details className="border-border mt-3 rounded-md border">
                <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                    Inspect QA findings
                </summary>
                <pre className="bg-muted/50 border-border max-h-72 overflow-y-auto border-t p-3 text-xs leading-5 break-all whitespace-pre-wrap">
                    {serializeFindings(review.findings)}
                </pre>
            </details>
        </div>
    );
}

/**
 * Render complete durable workflow evidence while making Agent turns the
 * dominant chronology and infrastructure evidence visually secondary.
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
        <div className="before:bg-border relative grid gap-3 pl-6 before:absolute before:top-5 before:bottom-5 before:left-2 before:w-px">
            {items.map((item) => (
                <div key={item.key} className="relative min-w-0">
                    <span
                        className="border-background bg-card ring-border absolute top-6 -left-[1.125rem] size-2.5 rounded-full border-2 ring-1"
                        aria-hidden="true"
                    />

                    {item.kind === 'run' && (
                        <AgentRunTimelineRow
                            session={item.session}
                            run={item.run}
                            handoffs={item.handoffs}
                            reviews={item.reviews}
                        />
                    )}

                    {item.kind === 'handoff' && (
                        <StandaloneHandoffTimelineRow handoff={item.handoff} />
                    )}

                    {item.kind === 'review' && (
                        <StandaloneReviewTimelineRow review={item.review} />
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * Determine whether a waiting Task has durable operator-facing handoff
 * instructions that should expose the Continue workflow.
 */
function hasWaitingInstruction(task: Task): boolean {
    return (
        task.status === 'waiting' &&
        Boolean(task.last_handoff?.id) &&
        Boolean(task.last_handoff?.note)
    );
}

/**
 * Render only the compact Task actions already supported by persisted state
 * and existing TaskController contracts.
 */
function TaskActionButtons({
    project,
    task,
}: {
    project: ProjectSummary;
    task: Task;
}) {
    const hasDispatchableHandoff = Boolean(task.last_handoff?.id);
    const waitingWithInstruction = hasWaitingInstruction(task);

    const canRun =
        hasDispatchableHandoff &&
        (task.status === 'pending' ||
            (task.status === 'waiting' && !waitingWithInstruction));

    return (
        <div className="flex min-w-0 flex-wrap items-center justify-end gap-2">
            {canRun && (
                <Form {...runTask.form([project.id, task.id])}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
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
        </div>
    );
}

/**
 * Render the existing optional operator instruction and Continue contract
 * without allowing the form to distort the primary Task header controls.
 */
function WaitingContinuationPanel({
    project,
    task,
}: {
    project: ProjectSummary;
    task: Task;
}) {
    if (!hasWaitingInstruction(task)) {
        return null;
    }

    return (
        <>
            <Separator />
            <div className="p-4">
                <Form
                    {...runTask.form([project.id, task.id])}
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1">
                                <p className="text-sm font-medium">
                                    Waiting for next Agent turn
                                </p>
                                <p className="text-muted-foreground text-sm leading-5 break-words whitespace-pre-wrap">
                                    {task.last_handoff?.note}
                                </p>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                <div className="grid min-w-0 gap-2">
                                    <Label
                                        htmlFor={`task-${task.id}-operator-instruction`}
                                        className="text-xs"
                                    >
                                        Optional operator instruction
                                    </Label>
                                    <textarea
                                        id={`task-${task.id}-operator-instruction`}
                                        name="operator_instruction"
                                        placeholder="Instruction for the next Agent turn..."
                                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring min-h-20 min-w-0 resize-y rounded-md border px-3 py-2 text-sm outline-none focus-visible:ring-2"
                                    />
                                    <InputError
                                        message={errors.operator_instruction}
                                    />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Continue
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

/**
 * Render the dominant Task identity, objective, state, and supported actions
 * across the full workspace width.
 */
function TaskHeader({
    project,
    task,
}: {
    project: ProjectSummary;
    task: Task;
}) {
    return (
        <Card className="gap-0 py-0 shadow-none">
            <CardHeader className="p-5">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight md:text-2xl">
                                {task.title}
                            </h1>
                            <Badge variant="outline">Task #{task.id}</Badge>
                            <StatusBadge status={task.status} />
                        </div>

                        <p className="text-muted-foreground mt-2 max-w-4xl text-sm leading-6">
                            <span className="text-foreground font-medium">
                                Objective:
                            </span>{' '}
                            {task.objective}
                        </p>
                    </div>

                    <TaskActionButtons project={project} task={task} />
                </div>
            </CardHeader>

            <WaitingContinuationPanel project={project} task={task} />
        </Card>
    );
}

/**
 * Return the newest CandidateReview using durable creation timestamps.
 */
function latestCandidateReview(
    reviews: CandidateReview[],
): CandidateReview | null {
    return (
        [...reviews].sort(
            (left, right) =>
                timestampValue(right.created_at) -
                timestampValue(left.created_at),
        )[0] ?? null
    );
}

/**
 * Render the narrow sticky Task inspector as one bordered surface with compact
 * aligned metadata and progressive disclosure for long persisted values.
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
        [...task.handoffs].sort((left, right) => right.id - left.id)[0] ?? null;

    return (
        <Card className="gap-0 py-0 shadow-none">
            <CardHeader className="border-border bg-card z-10 border-b px-4 py-3.5 lg:sticky lg:top-0">
                <h2 className="font-semibold">Task Details</h2>
            </CardHeader>

            <CardContent className="p-0">
                <DetailsSection title="Overview">
                    <dl className="grid gap-2.5">
                        <MetadataField label="Objective">
                            <div className="max-h-28 overflow-y-auto pr-1 break-words whitespace-pre-wrap">
                                {task.objective}
                            </div>
                        </MetadataField>

                        <MetadataField label="Implementation spec">
                            {task.implementation_spec ? (
                                <details className="min-w-0">
                                    <summary className="focus-visible:ring-ring cursor-pointer rounded-sm text-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2">
                                        View spec
                                    </summary>
                                    <div className="text-muted-foreground mt-2 max-h-48 overflow-y-auto text-xs leading-5 break-all whitespace-pre-wrap">
                                        {task.implementation_spec}
                                    </div>
                                </details>
                            ) : (
                                <span className="text-muted-foreground">
                                    Not recorded
                                </span>
                            )}
                        </MetadataField>
                    </dl>
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
                            {task.repair_cycle_count} /{' '}
                            {task.repair_cycle_limit}
                        </MetadataField>

                        <MetadataField label="Protocol recoveries">
                            {task.protocol_recovery_count}
                        </MetadataField>

                        <MetadataField label="Latest handoff">
                            {latestHandoff ? (
                                <span className="capitalize">
                                    {formatRoleRoute(
                                        latestHandoff.from_role,
                                        latestHandoff.to_role,
                                    )}
                                </span>
                            ) : task.last_handoff?.to_role ? (
                                <span className="capitalize">
                                    {task.last_handoff.from_role
                                        ? formatRoleRoute(
                                              task.last_handoff.from_role,
                                              task.last_handoff.to_role,
                                          )
                                        : `To ${humanize(task.last_handoff.to_role)}`}
                                </span>
                            ) : (
                                'None'
                            )}
                        </MetadataField>

                        {(task.blocked_reason ||
                            task.outcome === 'blocked') && (
                            <MetadataField label="Blocked reason">
                                <span className="text-destructive break-words whitespace-pre-wrap">
                                    {task.blocked_reason ||
                                        'Blocked without an additional recorded reason.'}
                                </span>
                            </MetadataField>
                        )}
                    </dl>
                </DetailsSection>

                <Separator />

                <DetailsSection title="Candidate & Repository">
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
                            <CopyableSha
                                value={task.candidate_tree_sha}
                                label="candidate tree SHA"
                            />
                        </MetadataField>

                        <MetadataField label="Commit SHA">
                            <CopyableSha
                                value={task.commit_sha}
                                label="commit SHA"
                            />
                        </MetadataField>

                        <MetadataField label="Worktree" mono>
                            {task.worktree_path ?? 'Not recorded'}
                        </MetadataField>

                        <MetadataField label="Changed files">
                            {task.changed_files.length}
                        </MetadataField>
                    </dl>

                    {task.changed_files.length > 0 && (
                        <details className="border-border rounded-md border">
                            <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
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
                </DetailsSection>

                <Separator />

                <DetailsSection title="QA Review">
                    {!latestReview ? (
                        <p className="text-muted-foreground text-sm">
                            No QA review has been recorded for this Task yet.
                        </p>
                    ) : (
                        <>
                            <dl className="grid gap-2.5">
                                <MetadataField label="Approval state">
                                    <ReviewStatusBadge
                                        status={latestReview.status}
                                    />
                                </MetadataField>

                                <MetadataField label="Reviewed candidate">
                                    <CopyableSha
                                        value={latestReview.candidate_tree_sha}
                                        label="reviewed candidate SHA"
                                    />
                                </MetadataField>

                                <MetadataField label="Summary">
                                    {latestReview.summary ? (
                                        <div className="max-h-32 overflow-y-auto pr-1 break-words whitespace-pre-wrap">
                                            {latestReview.summary}
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Not recorded
                                        </span>
                                    )}
                                </MetadataField>
                            </dl>

                            <details className="border-border rounded-md border">
                                <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                                    Latest findings
                                </summary>
                                <pre className="bg-muted/50 border-border max-h-64 overflow-y-auto border-t p-3 text-xs leading-5 break-all whitespace-pre-wrap">
                                    {serializeFindings(latestReview.findings)}
                                </pre>
                            </details>
                        </>
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

                        <MetadataField label="Summary">
                            {workRequest.summary ? (
                                <div className="max-h-28 overflow-y-auto pr-1 break-words whitespace-pre-wrap">
                                    {workRequest.summary}
                                </div>
                            ) : (
                                <span className="text-muted-foreground">
                                    Not recorded
                                </span>
                            )}
                        </MetadataField>
                    </dl>

                    <details className="border-border rounded-md border">
                        <summary className="focus-visible:ring-ring cursor-pointer rounded-md px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-inset">
                            Inspect request prompt
                        </summary>
                        <div className="border-border max-h-56 overflow-y-auto border-t p-3 text-sm leading-6 break-all whitespace-pre-wrap">
                            {workRequest.prompt}
                        </div>
                    </details>

                    {workRequest.failure_reason && (
                        <div className="border-destructive/30 bg-destructive/5 rounded-md border p-3">
                            <p className="text-destructive text-xs font-medium">
                                Failure information
                            </p>
                            <p className="text-muted-foreground mt-1 text-sm leading-5 break-all whitespace-pre-wrap">
                                {workRequest.failure_reason}
                            </p>
                        </div>
                    )}
                </DetailsSection>
            </CardContent>
        </Card>
    );
}

/**
 * Render the dedicated Task workspace with Agent workflow history dominant on
 * the left and the sticky Task inspector alongside it from the lg breakpoint.
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

            <main className="mx-auto flex w-full max-w-[1800px] min-w-0 flex-col gap-4 p-4 lg:p-5 xl:p-6">
                <TaskHeader project={project} task={task} />

                <div className="grid min-w-0 gap-4 min-[1440px]:grid-cols-[minmax(0,1fr)_20rem] lg:grid-cols-[minmax(0,1fr)_18rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <section
                        className="min-w-0"
                        aria-labelledby="workflow-history-heading"
                    >
                        <Card className="gap-0 py-0 shadow-none">
                            <CardHeader className="border-border border-b px-4 py-3.5">
                                <div>
                                    <h2
                                        id="workflow-history-heading"
                                        className="font-semibold"
                                    >
                                        Agent workflow history
                                    </h2>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        Chronological Agent turns with durable
                                        handoff and QA evidence.
                                    </p>
                                </div>
                            </CardHeader>

                            <CardContent className="p-4">
                                <WorkflowTimeline task={task} />
                            </CardContent>
                        </Card>
                    </section>

                    <aside className="min-w-0 lg:sticky lg:top-16 lg:max-h-[calc(100vh-5rem)] lg:self-start lg:overflow-y-auto lg:overscroll-contain lg:pr-1">
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
