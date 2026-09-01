import { Head, Link } from '@inertiajs/react';
import {
    Bot,
    CircleCheck,
    CircleMinus,
    Info,
    Puzzle,
    Settings2,
} from 'lucide-react';
import { edit } from '@/actions/App/Http/Controllers/ProjectAgentController';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { index as projectsIndex, show as showProject } from '@/routes/projects';
import { index as agentsIndex } from '@/routes/projects/agents';
import { index as skillsIndex } from '@/routes/projects/skills';

type ProjectSummary = {
    id: number;
    title: string;
};

type Skill = {
    id: number;
    name: string;
};

type Agent = {
    id: number;
    role: string;
    name: string;
    harness: string;
    model: string | null;
    enabled: boolean;
    skills: Skill[];
};

type AgentsPageProps = {
    project: ProjectSummary;
    agents: Agent[];
};

const AGENT_AVATARS: Record<string, string> = {
    project_manager: '/images/agents/project-manager.png',
    architect: '/images/agents/architect.png',
    coder: '/images/agents/coder.png',
    qa: '/images/agents/qa.png',
    devops: '/images/agents/devops.png',
};

/**
 * Render the responsive project Agent configuration workspace.
 */
export default function Agents({ project, agents }: AgentsPageProps) {
    return (
        <>
            <Head title={`${project.title} Agents`} />

            <section
                aria-labelledby="agents-heading"
                className="flex w-full flex-1 flex-col gap-8 p-4 sm:p-6 lg:p-8"
            >
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <h1
                            id="agents-heading"
                            className="text-2xl font-semibold tracking-tight"
                        >
                            Agents
                        </h1>

                        <p className="text-muted-foreground mt-1">
                            Configure the AI team assigned to this project.
                        </p>
                    </div>

                    <Button
                        asChild
                        variant="outline"
                        className="w-full sm:w-auto"
                    >
                        <Link href={skillsIndex(project)}>
                            <Puzzle aria-hidden="true" />
                            Manage Skills
                        </Link>
                    </Button>
                </header>

                {agents.length === 0 ? (
                    <div className="border-border bg-card flex min-h-72 flex-col items-center justify-center rounded-xl border px-6 py-12 text-center">
                        <div className="flex max-w-sm flex-col items-center gap-4">
                            <div className="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full">
                                <Bot aria-hidden="true" className="size-6" />
                            </div>

                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    No Agents configured
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    This project does not currently have any
                                    available Agent configurations.
                                </p>
                            </div>
                        </div>
                    </div>
                ) : (
                    <ul
                        role="list"
                        className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[repeat(auto-fit,minmax(14rem,15rem))] 2xl:justify-start"
                    >
                        {agents.map((agent) => {
                            const titleId = `agent-${agent.id}-title`;

                            return (
                                <li key={agent.id} className="min-w-0">
                                    <article
                                        aria-labelledby={titleId}
                                        className="h-full"
                                    >
                                        <Card className="hover:border-foreground/20 focus-within:border-ring h-full min-h-[34rem] gap-0 py-0 shadow-none transition-[border-color,box-shadow] focus-within:shadow-sm hover:shadow-sm">
                                            <CardHeader className="gap-5 px-5 pt-5 pb-0">
                                                <div className="flex items-center justify-between">
                                                    <Badge
                                                        variant={
                                                            agent.enabled
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                        className={
                                                            agent.enabled
                                                                ? undefined
                                                                : 'text-muted-foreground'
                                                        }
                                                    >
                                                        {agent.enabled ? (
                                                            <CircleCheck aria-hidden="true" />
                                                        ) : (
                                                            <CircleMinus aria-hidden="true" />
                                                        )}
                                                        {agent.enabled
                                                            ? 'Enabled'
                                                            : 'Disabled'}
                                                    </Badge>
                                                </div>

                                                <div className="flex justify-center py-1">
                                                    <Avatar className="border-border size-32 border shadow-xs 2xl:size-36">
                                                        <AvatarImage
                                                            src={
                                                                AGENT_AVATARS[
                                                                    agent.role
                                                                ]
                                                            }
                                                            alt={`${agent.name} avatar`}
                                                            className="object-cover"
                                                        />
                                                        <AvatarFallback>
                                                            {agent.name
                                                                .slice(0, 2)
                                                                .toUpperCase()}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                </div>

                                                <div className="min-w-0">
                                                    <h2
                                                        id={titleId}
                                                        className="text-xl leading-6 font-semibold tracking-tight break-words"
                                                    >
                                                        {agent.name}
                                                    </h2>

                                                    <p className="text-muted-foreground mt-1 text-sm capitalize">
                                                        {agent.role.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </p>
                                                </div>
                                            </CardHeader>

                                            <CardContent className="flex flex-1 flex-col gap-5 px-5 pt-5 pb-0">
                                                <Separator />

                                                <dl className="grid gap-3 text-sm">
                                                    <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] items-start gap-3">
                                                        <dt className="text-muted-foreground">
                                                            Harness
                                                        </dt>
                                                        <dd className="min-w-0 text-right font-medium break-words capitalize">
                                                            {agent.harness}
                                                        </dd>
                                                    </div>

                                                    <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] items-start gap-3">
                                                        <dt className="text-muted-foreground">
                                                            Model
                                                        </dt>
                                                        <dd className="min-w-0 text-right font-medium break-words">
                                                            {agent.model ??
                                                                'Default'}
                                                        </dd>
                                                    </div>
                                                </dl>

                                                <Separator />

                                                <div className="min-w-0">
                                                    <h3 className="text-sm font-medium">
                                                        Skills
                                                    </h3>

                                                    {agent.skills.length > 0 ? (
                                                        <div className="mt-3 flex flex-wrap gap-1.5">
                                                            {agent.skills.map(
                                                                (skill) => (
                                                                    <Badge
                                                                        key={
                                                                            skill.id
                                                                        }
                                                                        variant="outline"
                                                                        className="bg-background text-muted-foreground max-w-full px-2.5 py-1 text-left leading-4 font-normal whitespace-normal"
                                                                    >
                                                                        {
                                                                            skill.name
                                                                        }
                                                                    </Badge>
                                                                ),
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <p className="text-muted-foreground mt-2 text-sm">
                                                            None
                                                        </p>
                                                    )}
                                                </div>
                                            </CardContent>

                                            <CardFooter className="mt-auto px-5 pt-5 pb-5">
                                                <Button
                                                    asChild
                                                    className="w-full"
                                                >
                                                    <Link
                                                        href={edit([
                                                            project,
                                                            agent,
                                                        ])}
                                                        aria-label={`Configure ${agent.name}`}
                                                    >
                                                        <Settings2 aria-hidden="true" />
                                                        Configure
                                                    </Link>
                                                </Button>
                                            </CardFooter>
                                        </Card>
                                    </article>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {agents.length > 0 && (
                    <div className="text-muted-foreground flex items-start justify-center gap-2 text-sm sm:items-center">
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 sm:mt-0"
                        />
                        <p>
                            Manage each Agent's configuration, model, and
                            assigned skills.
                        </p>
                    </div>
                )}
            </section>
        </>
    );
}

/**
 * Supply dynamic project breadcrumbs to the persistent application layout.
 */
Agents.layout = (props: AgentsPageProps) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: projectsIndex(),
        },
        {
            title: props.project.title,
            href: showProject(props.project.id),
        },
        {
            title: 'Agents',
            href: agentsIndex(props.project.id),
        },
    ],
});
