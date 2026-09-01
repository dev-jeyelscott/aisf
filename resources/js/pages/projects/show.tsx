import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    Ban,
    CheckCircle2,
    FolderGit2,
    GitBranch,
    GitCommitHorizontal,
    LoaderCircle,
    Pause,
    Pencil,
    Play,
    Plus,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import {
    humanize,
    shortSha,
    StatusBadge,
    type Task,
    type WorkRequest,
} from '@/components/projects/tasks/task-ui';
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
import { show as showTask } from '@/routes/projects/tasks';
import { store as storeWorkRequest } from '@/routes/projects/work-requests';

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

type TaskItem = {
    task: Task;
    request: WorkRequest;
};

/**
 * Generate a deterministic visual monogram from the persisted Project title.
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
 * Submit the complete Project update contract while changing only enabled state.
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
 * Render compact repository state from the existing RepositoryInspector payload.
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
 * Render the dominant Project identity, repository context, and supported controls.
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
 * Render the existing manual WorkRequest submission contract in a dialog.
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
 * Render a concise Task card whose Inspect action opens the dedicated Task page.
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

                <Button asChild variant="outline" size="sm">
                    <Link href={showTask([project.id, task.id])}>Inspect</Link>
                </Button>
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

    return (
        <div className="border-border bg-card flex min-h-64 flex-col items-center justify-center rounded-xl border p-8 text-center">
            <AlertCircle className="text-muted-foreground size-8" />
            <h3 className="mt-4 text-base font-semibold">
                No planned Tasks available
            </h3>
            <p className="text-muted-foreground mt-2 max-w-lg text-sm">
                Existing WorkRequests have not produced a persisted Task that
                can be displayed here.
            </p>
        </div>
    );
}

/**
 * Render Tasks as the dominant responsive Project dashboard surface.
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
 * Render the Project control surface with Tasks prioritized for scanning.
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
