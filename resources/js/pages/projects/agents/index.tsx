import { Head, Link } from '@inertiajs/react';
import { Pencil, Puzzle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { edit } from '@/actions/App/Http/Controllers/ProjectAgentController';
import { index as skills } from '@/routes/projects/skills';

type Agent = {
    id: number;
    role: string;
    name: string;
    harness: string;
    model: string | null;
    enabled: boolean;
    skills: { id: number; name: string }[];
};
export default function Agents({
    project,
    agents,
}: {
    project: { id: number; title: string };
    agents: Agent[];
}) {
    return (
        <>
            <Head title={`${project.title} Agents`} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-muted-foreground text-sm">
                            Project configuration
                        </p>
                        <h1 className="text-2xl font-semibold">Agents</h1>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={skills(project)}>
                            <Puzzle />
                            Manage Skills
                        </Link>
                    </Button>
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    {agents.map((agent) => (
                        <article
                            key={agent.id}
                            className="border-border bg-card rounded-xl border p-5"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-medium">
                                        {agent.name}
                                    </h2>
                                    <p className="text-muted-foreground mt-1 text-sm capitalize">
                                        {agent.role.replaceAll('_', ' ')}
                                    </p>
                                </div>
                                <span className="bg-muted rounded-full px-2 py-1 text-xs">
                                    {agent.enabled ? 'Enabled' : 'Disabled'}
                                </span>
                            </div>
                            <dl className="text-muted-foreground mt-4 space-y-1 text-sm">
                                <div>Harness: {agent.harness}</div>
                                <div>Model: {agent.model ?? 'Default'}</div>
                                <div>
                                    Skills:{' '}
                                    {agent.skills
                                        .map((skill) => skill.name)
                                        .join(', ') || 'None'}
                                </div>
                            </dl>
                            <Button asChild size="sm" className="mt-5">
                                <Link href={edit([project, agent])}>
                                    <Pencil />
                                    Configure
                                </Link>
                            </Button>
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}
