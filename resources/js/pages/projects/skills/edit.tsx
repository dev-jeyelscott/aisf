import { Form, Head, Link } from '@inertiajs/react';
import SkillController from '@/actions/App/Http/Controllers/ProjectSkillController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/projects/skills';
export default function EditSkill({
    project,
    skill,
}: {
    project: { id: number; title: string };
    skill: {
        id: number;
        name: string;
        description: string | null;
        instructions: string;
        enabled: boolean;
    };
}) {
    return (
        <>
            <Head title={`Edit ${skill.name}`} />
            <div className="mx-auto w-full max-w-2xl p-4 md:p-8">
                <Button asChild variant="ghost">
                    <Link href={index(project)}>Back to Skills</Link>
                </Button>
                <h1 className="mt-4 text-2xl font-semibold">Edit Skill</h1>
                <Form
                    {...SkillController.update.form([project, skill])}
                    className="border-border bg-card mt-5 grid gap-4 rounded-xl border p-5"
                >
                    {({ processing }) => (
                        <>
                            <Input
                                name="name"
                                required
                                defaultValue={skill.name}
                            />
                            <textarea
                                name="description"
                                defaultValue={skill.description ?? ''}
                                className="border-input bg-background min-h-20 rounded-md border p-3 text-sm"
                            />
                            <textarea
                                name="instructions"
                                required
                                defaultValue={skill.instructions}
                                className="border-input bg-background min-h-32 rounded-md border p-3 text-sm"
                            />
                            <input type="hidden" name="enabled" value="0" />
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="enabled"
                                    name="enabled"
                                    value="1"
                                    defaultChecked={skill.enabled}
                                />
                                <label htmlFor="enabled">Enabled</label>
                            </div>
                            <Button type="submit" disabled={processing}>
                                Save Skill
                            </Button>
                        </>
                    )}
                </Form>
                <Form
                    {...SkillController.destroy.form([project, skill])}
                    className="mt-4"
                >
                    <Button variant="destructive">Delete Skill</Button>
                </Form>
            </div>
        </>
    );
}
