import { Form, Head, Link } from '@inertiajs/react';
import AgentController from '@/actions/App/Http/Controllers/ProjectAgentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/projects/agents';
type Skill = { id: number; name: string };
type Agent = {
    id: number;
    name: string;
    identity: string | null;
    harness: string;
    model: string | null;
    settings: Record<string, unknown> | null;
    default_context: string | null;
    workflow_instructions: string | null;
    enabled: boolean;
    skills: Skill[];
};
export default function EditAgent({
    project,
    agent,
    skills,
}: {
    project: { id: number; title: string };
    agent: Agent;
    skills: Skill[];
}) {
    const assigned = new Set(agent.skills.map((skill) => skill.id));
    return (
        <>
            <Head title={`Configure ${agent.name}`} />
            <div className="mx-auto w-full max-w-3xl p-4 md:p-8">
                <Button asChild variant="ghost">
                    <Link href={index(project)}>Back to Agents</Link>
                </Button>
                <h1 className="mt-4 text-2xl font-semibold">
                    Configure {agent.name}
                </h1>
                <Form
                    {...AgentController.update.form([project, agent])}
                    className="border-border bg-card mt-6 grid gap-5 rounded-xl border p-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                label="Name"
                                name="name"
                                value={agent.name}
                                error={errors.name}
                            />
                            <Field
                                label="Identity"
                                name="identity"
                                value={agent.identity ?? ''}
                                error={errors.identity}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="harness">Harness</Label>
                                <select
                                    id="harness"
                                    name="harness"
                                    defaultValue={agent.harness}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                >
                                    <option value="codex">Codex</option>
                                    <option value="claude">Claude</option>
                                </select>
                                <InputError message={errors.harness} />
                            </div>
                            <Field
                                label="Model"
                                name="model"
                                value={agent.model ?? ''}
                                error={errors.model}
                            />
                            <Area
                                label="Settings JSON"
                                name="settings"
                                value={
                                    agent.settings
                                        ? JSON.stringify(
                                              agent.settings,
                                              null,
                                              2,
                                          )
                                        : ''
                                }
                                error={errors.settings}
                            />
                            <Area
                                label="Default context"
                                name="default_context"
                                value={agent.default_context ?? ''}
                                error={errors.default_context}
                            />
                            <Area
                                label="Workflow instructions"
                                name="workflow_instructions"
                                value={agent.workflow_instructions ?? ''}
                                error={errors.workflow_instructions}
                            />
                            <div className="grid gap-3">
                                <Label>Assigned skills</Label>
                                {skills.map((skill, index) => (
                                    <div
                                        key={skill.id}
                                        className="flex items-center gap-3"
                                    >
                                        <Checkbox
                                            name="skill_ids[]"
                                            value={skill.id.toString()}
                                            defaultChecked={assigned.has(
                                                skill.id,
                                            )}
                                            id={`skill-${skill.id}`}
                                        />
                                        <Label htmlFor={`skill-${skill.id}`}>
                                            {skill.name}
                                        </Label>
                                        <Input
                                            className="ml-auto w-20"
                                            type="number"
                                            min="1"
                                            name={`skill_positions[${skill.id}]`}
                                            defaultValue={index + 1}
                                        />
                                    </div>
                                ))}
                            </div>
                            <input type="hidden" name="enabled" value="0" />
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    name="enabled"
                                    value="1"
                                    id="enabled"
                                    defaultChecked={agent.enabled}
                                />
                                <Label htmlFor="enabled">Enable agent</Label>
                            </div>
                            <Button type="submit" disabled={processing}>
                                Save agent
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
function Field({
    label,
    name,
    value,
    error,
}: {
    label: string;
    name: string;
    value: string;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} name={name} defaultValue={value} />
            <InputError message={error} />
        </div>
    );
}
function Area({
    label,
    name,
    value,
    error,
}: {
    label: string;
    name: string;
    value: string;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <textarea
                id={name}
                name={name}
                defaultValue={value}
                className="border-input bg-background min-h-24 rounded-md border p-3 text-sm"
            />
            <InputError message={error} />
        </div>
    );
}
