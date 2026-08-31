import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Project = {
    id: number;
    title: string;
    description: string | null;
    path: string;
    enabled: boolean;
    merge_policy: 'human' | 'automatic';
};

export function ProjectForm({
    action,
    project,
    submitLabel,
}: {
    action: { action: string; method: 'post' };
    project?: Project;
    submitLabel: string;
}) {
    return (
        <Form {...action} className="grid gap-6">
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="title">Project name</Label>
                        <Input
                            id="title"
                            name="title"
                            required
                            autoFocus
                            defaultValue={project?.title}
                            placeholder="AISF"
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>
                        <textarea
                            id="description"
                            name="description"
                            defaultValue={project?.description ?? ''}
                            placeholder="What are you building?"
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring min-h-24 rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="path">Local repository path</Label>
                        <Input
                            id="path"
                            name="path"
                            required
                            defaultValue={project?.path}
                            placeholder="/path/to/repository"
                            aria-describedby="path-help"
                        />
                        <p
                            id="path-help"
                            className="text-muted-foreground text-sm"
                        >
                            Enabled projects must point to an existing Git
                            working tree.
                        </p>
                        <InputError message={errors.path} />
                    </div>

                    <div className="flex items-start gap-3">
                        <input type="hidden" name="enabled" value="0" />
                        <Checkbox
                            id="enabled"
                            name="enabled"
                            value="1"
                            defaultChecked={project?.enabled ?? true}
                        />
                        <div className="grid gap-1.5">
                            <Label htmlFor="enabled">Enable this project</Label>
                            <p className="text-muted-foreground text-sm">
                                Disabled projects are saved without repository
                                validation.
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="merge_policy">Merge policy</Label>
                        <select
                            id="merge_policy"
                            name="merge_policy"
                            defaultValue={project?.merge_policy ?? 'human'}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="human">
                                Human approval required
                            </option>
                            <option value="automatic">
                                Automatic after AISF gates
                            </option>
                        </select>
                        <p className="text-muted-foreground text-sm">
                            Automatic merge requires AISF CI and an independent
                            review of the exact candidate commit.
                        </p>
                        <InputError message={errors.merge_policy} />
                    </div>

                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-fit"
                    >
                        {processing && <Spinner />}
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}
