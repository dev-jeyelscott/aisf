import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    GitBranch,
    Pencil,
    Users,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/projects';
import { index as agents } from '@/routes/projects/agents';

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

const placeholders = ['Prompt', 'Tasks', 'Agents', 'Activity'];

export default function ProjectWorkspace({
    project,
    repositoryStatus,
}: {
    project: Project;
    repositoryStatus: RepositoryStatus | null;
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
