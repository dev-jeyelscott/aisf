import { Head } from '@inertiajs/react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import { ProjectForm } from '@/components/project-form';
import { create, index } from '@/routes/projects';

export default function CreateProject() {
    return (
        <>
            <Head title="Create Project" />
            <div className="mx-auto w-full max-w-2xl p-4 md:p-8">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Create Project
                </h1>
                <p className="text-muted-foreground mt-1">
                    Add a local repository to the workspace.
                </p>
                <div className="border-border bg-card mt-6 rounded-xl border p-6">
                    <ProjectForm
                        action={ProjectController.store.form()}
                        submitLabel="Create Project"
                    />
                </div>
            </div>
        </>
    );
}

CreateProject.layout = {
    breadcrumbs: [
        { title: 'Projects', href: index() },
        { title: 'Create Project', href: create() },
    ],
};
