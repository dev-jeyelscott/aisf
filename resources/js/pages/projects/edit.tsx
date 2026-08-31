import { Head } from '@inertiajs/react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import { ProjectForm } from '@/components/project-form';
import { index } from '@/routes/projects';

type Project = {
    id: number;
    title: string;
    description: string | null;
    path: string;
    enabled: boolean;
    merge_policy: 'human' | 'automatic';
};

export default function EditProject({ project }: { project: Project }) {
    return (
        <>
            <Head title={`Edit ${project.title}`} />
            <div className="mx-auto w-full max-w-2xl p-4 md:p-8">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Edit Project
                </h1>
                <p className="text-muted-foreground mt-1">
                    Update the project configuration.
                </p>
                <div className="border-border bg-card mt-6 rounded-xl border p-6">
                    <ProjectForm
                        action={ProjectController.update.form(project)}
                        project={project}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
