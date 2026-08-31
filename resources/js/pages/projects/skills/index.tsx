import { Form, Head, Link } from '@inertiajs/react';
import SkillController from '@/actions/App/Http/Controllers/ProjectSkillController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as agents } from '@/routes/projects/agents';
type Skill = {
    id: number;
    name: string;
    description: string | null;
    instructions: string;
    enabled: boolean;
};
export default function Skills({
    project,
    skills,
}: {
    project: { id: number; title: string };
    skills: Skill[];
}) {
    return (
        <>
            <Head title={`${project.title} Skills`} />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:grid-cols-[1fr_1fr] md:p-8">
                <section>
                    <Button asChild variant="ghost">
                        <Link href={agents(project)}>Back to Agents</Link>
                    </Button>
                    <h1 className="mt-4 text-2xl font-semibold">Skills</h1>
                    <div className="mt-5 space-y-3">
                        {skills.map((skill) => (
                            <article
                                key={skill.id}
                                className="border-border bg-card rounded-lg border p-4"
                            >
                                <div className="flex justify-between">
                                    <h2 className="font-medium">
                                        {skill.name}
                                    </h2>
                                    <span className="text-muted-foreground text-xs">
                                        {skill.enabled ? 'Enabled' : 'Disabled'}
                                    </span>
                                </div>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {skill.description}
                                </p>
                                <Button
                                    asChild
                                    className="mt-3"
                                    size="sm"
                                    variant="outline"
                                >
                                    <Link
                                        href={SkillController.edit([
                                            project,
                                            skill,
                                        ])}
                                    >
                                        Edit
                                    </Link>
                                </Button>
                            </article>
                        ))}
                    </div>
                </section>
                <section className="border-border bg-card h-fit rounded-xl border p-5">
                    <h2 className="font-medium">Create Skill</h2>
                    <Form
                        {...SkillController.store.form(project)}
                        className="mt-4 grid gap-4"
                    >
                        {({ processing }) => (
                            <>
                                <Input
                                    name="name"
                                    required
                                    placeholder="Skill name"
                                />
                                <textarea
                                    name="description"
                                    placeholder="Description"
                                    className="border-input bg-background min-h-20 rounded-md border p-3 text-sm"
                                />
                                <textarea
                                    name="instructions"
                                    required
                                    placeholder="Instructions"
                                    className="border-input bg-background min-h-32 rounded-md border p-3 text-sm"
                                />
                                <input type="hidden" name="enabled" value="0" />
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="enabled"
                                        name="enabled"
                                        value="1"
                                        defaultChecked
                                    />
                                    <Label htmlFor="enabled">Enabled</Label>
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Create Skill
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
