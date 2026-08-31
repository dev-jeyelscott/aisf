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
};

type WorkRequest = {
    id: number;
    prompt: string;
    status: string;
    summary: string | null;
    evidence: string[] | null;
    failure_reason: string | null;
    tasks: Task[];
};

const placeholders = ['Agents', 'Activity'];

export default function ProjectWorkspace({
    project,
    repositoryStatus,
    workRequests = [],
}: {
    project: Project;
    repositoryStatus: RepositoryStatus | null;
    workRequests?: WorkRequest[];
}) {
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
                                {request.status.replace(/_/g, ' ')}
                            </span>
                        </div>

                        <p className="mt-3 whitespace-pre-wrap text-sm">
                            {request.prompt}
                        </p>

                        {request.summary && (
                            <div className="mt-4">
                                <h3 className="text-sm font-medium">PM summary</h3>
                                <p className="text-muted-foreground mt-1 whitespace-pre-wrap text-sm">
                                    {request.summary}
                                </p>
                            </div>
                        )}

                        {request.evidence && request.evidence.length > 0 && (
                            <div className="mt-4">
                                <h3 className="text-sm font-medium">
                                    Repository evidence
                                </h3>
                                <ul className="text-muted-foreground mt-2 list-disc space-y-1 pl-5 text-sm">
                                    {request.evidence.map((evidence, index) => (
                                        <li key={`${request.id}-evidence-${index}`}>
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
                                                    <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                                                        Task {task.position}
                                                    </p>
                                                    <h4 className="mt-1 font-medium">
                                                        {task.title}
                                                    </h4>
                                                </div>
                                                {dependency && (
                                                    <span className="text-muted-foreground text-xs">
                                                        Depends on Task{' '}
                                                        {dependency.position}:{' '}
                                                        {dependency.title}
                                                    </span>
                                                )}
                                            </div>

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
                                                        Implementation specification
                                                    </h5>
                                                    <p className="text-muted-foreground mt-1 whitespace-pre-wrap">
                                                        {task.implementation_spec}
                                                    </p>
                                                </div>
                                                <div>
                                                    <h5 className="font-medium">
                                                        Acceptance criteria
                                                    </h5>
                                                    <ul className="text-muted-foreground mt-1 list-disc space-y-1 pl-5">
                                                        {task.acceptance_criteria.map(
                                                            (criterion, index) => (
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
                                                            (command, index) => (
                                                                <li
                                                                    key={`${task.id}-command-${index}`}
                                                                >
                                                                    <code className="bg-muted block overflow-x-auto rounded px-2 py-1.5 text-xs">
                                                                        {command}
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
                                        </article>
                                    );
                                })}
                            </div>
                        )}
                    </section>
                ))}

                <section
                    aria-label="Workspace areas"
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {placeholders.map((name) => (
                        <div
                            key={name}
                            className="border-border bg-card min-h-36 rounded-xl border p-5"
                        >
                            <h2 className="font-medium">{name}</h2>
                            <p className="text-muted-foreground mt-2 text-sm">
                                Coming soon.
                            </p>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}

ProjectWorkspace.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
