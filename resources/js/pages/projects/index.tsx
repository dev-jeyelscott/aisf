import { Head, Link } from '@inertiajs/react';
import { FolderGit2, Pencil, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    create,
    edit,
    show,
} from '@/actions/App/Http/Controllers/ProjectController';
import { index } from '@/routes/projects';

type Project = {
    id: number;
    title: string;
    description: string | null;
    path: string;
    enabled: boolean;
};

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    return (
        <>
            <Head title="Projects" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Projects
                        </h1>
                        <p className="text-muted-foreground mt-1">
                            Local Git repositories managed by AISF.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Create Project
                        </Link>
                    </Button>
                </div>

                {projects.length === 0 ? (
                    <div className="border-border bg-card flex min-h-80 flex-col items-center justify-center rounded-xl border p-8 text-center">
                        <FolderGit2 className="text-muted-foreground mb-4 size-10" />
                        <h2 className="text-lg font-medium">No projects yet</h2>
                        <p className="text-muted-foreground mt-2 max-w-md text-sm">
                            Register a local Git repository to inspect its
                            workspace.
                        </p>
                        <Button asChild className="mt-5">
                            <Link href={create()}>
                                <Plus />
                                Create Project
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        {projects.map((project) => (
                            <article
                                key={project.id}
                                className="border-border bg-card rounded-xl border p-5"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <h2 className="truncate text-lg font-medium">
                                            {project.title}
                                        </h2>
                                        <p className="text-muted-foreground mt-1 line-clamp-2 text-sm">
                                            {project.description ||
                                                'No description provided.'}
                                        </p>
                                    </div>
                                    <span className="bg-muted rounded-full px-2.5 py-1 text-xs font-medium">
                                        {project.enabled
                                            ? 'Enabled'
                                            : 'Disabled'}
                                    </span>
                                </div>
                                <p className="text-muted-foreground mt-4 truncate font-mono text-xs">
                                    {project.path}
                                </p>
                                <div className="mt-5 flex gap-2">
                                    <Button asChild size="sm">
                                        <Link href={show(project)}>Open</Link>
                                    </Button>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={edit(project)}>
                                            <Pencil />
                                            Edit
                                        </Link>
                                    </Button>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
