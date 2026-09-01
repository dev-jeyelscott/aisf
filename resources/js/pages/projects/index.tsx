import { Head, Link } from '@inertiajs/react';
import { FolderGit2, FolderOpen, Pencil, Plus } from 'lucide-react';
import {
    create,
    edit,
    show,
} from '@/actions/App/Http/Controllers/ProjectController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/projects';

type Project = {
    id: number;
    title: string;
    description: string | null;
    path: string;
    enabled: boolean;
};

/**
 * Render the Projects workspace with a responsive repository card gallery and empty state.
 */
export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    return (
        <>
            <Head title="Projects" />

            <section
                aria-labelledby="projects-heading"
                className="flex w-full flex-1 flex-col gap-8 p-4 sm:p-6 lg:p-8"
            >
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <h1
                            id="projects-heading"
                            className="text-2xl font-semibold tracking-tight"
                        >
                            Projects
                        </h1>
                        <p className="text-muted-foreground mt-1">
                            Local Git repositories managed by AISF.
                        </p>
                    </div>

                    <Button asChild className="w-full sm:w-auto">
                        <Link href={create()}>
                            <Plus aria-hidden="true" />
                            Create Project
                        </Link>
                    </Button>
                </header>

                {projects.length === 0 ? (
                    <div className="border-border bg-card flex min-h-80 flex-col items-center justify-center rounded-xl border px-6 py-12 text-center">
                        <div className="flex max-w-md flex-col items-center gap-4">
                            <div className="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-lg">
                                <FolderGit2
                                    aria-hidden="true"
                                    className="size-6"
                                />
                            </div>

                            <div className="flex flex-col gap-2">
                                <h2 className="text-lg font-medium">
                                    No projects yet
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Register a local Git repository to inspect
                                    its workspace.
                                </p>
                            </div>

                            <Button asChild>
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    Create Project
                                </Link>
                            </Button>
                        </div>
                    </div>
                ) : (
                    <ul
                        role="list"
                        className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                    >
                        {projects.map((project) => {
                            const titleId = `project-${project.id}-title`;

                            return (
                                <li key={project.id} className="min-w-0">
                                    <article
                                        aria-labelledby={titleId}
                                        className="border-border bg-card flex h-full min-h-72 min-w-0 flex-col rounded-xl border p-5 transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm focus-within:border-ring focus-within:shadow-sm"
                                    >
                                        <div className="flex min-w-0 flex-col gap-4">
                                            <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-lg">
                                                <FolderGit2
                                                    aria-hidden="true"
                                                    className="size-5"
                                                />
                                            </div>

                                            <div className="flex min-w-0 flex-col gap-1">
                                                <h2
                                                    id={titleId}
                                                    className="min-h-10 line-clamp-2 break-words text-base leading-5 font-semibold tracking-tight"
                                                >
                                                    {project.title}
                                                </h2>

                                                <p className="text-muted-foreground min-h-10 line-clamp-2 break-words text-sm leading-5">
                                                    {project.description ||
                                                        'No description provided.'}
                                                </p>
                                            </div>

                                            <div className="flex min-w-0 flex-col items-start gap-3">
                                                <p
                                                    title={project.path}
                                                    className="bg-muted text-muted-foreground w-fit max-w-full truncate rounded-md px-2.5 py-1.5 font-mono text-xs"
                                                >
                                                    {project.path}
                                                </p>

                                                <Badge
                                                    variant={
                                                        project.enabled
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                    className={
                                                        project.enabled
                                                            ? undefined
                                                            : 'text-muted-foreground'
                                                    }
                                                >
                                                    {project.enabled
                                                        ? 'Enabled'
                                                        : 'Disabled'}
                                                </Badge>
                                            </div>
                                        </div>

                                        <div className="border-border mt-auto flex gap-2 border-t pt-4">
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="flex-1"
                                            >
                                                <Link
                                                    href={show(project)}
                                                    aria-label={`Open ${project.title}`}
                                                >
                                                    <FolderOpen
                                                        aria-hidden="true"
                                                    />
                                                    Open
                                                </Link>
                                            </Button>

                                            <Button
                                                asChild
                                                variant="outline"
                                                className="flex-1"
                                            >
                                                <Link
                                                    href={edit(project)}
                                                    aria-label={`Edit ${project.title}`}
                                                >
                                                    <Pencil
                                                        aria-hidden="true"
                                                    />
                                                    Edit
                                                </Link>
                                            </Button>
                                        </div>
                                    </article>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
