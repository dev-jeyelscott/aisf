import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type AgentDefault = { id: number; role: string; instructions: string };

export default function AgentDefaults({
    defaults,
}: {
    defaults: AgentDefault[];
}) {
    return (
        <>
            <Head title="Agent defaults" />
            <main className="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
                <div>
                    <p className="text-muted-foreground text-sm">
                        AISF configuration
                    </p>
                    <h1 className="text-2xl font-semibold">
                        Global Agent defaults
                    </h1>
                </div>
                <Form
                    action="/agent-defaults"
                    method="put"
                    className="space-y-5"
                >
                    {({ processing }) => (
                        <>
                            {defaults.map((item, index) => (
                                <section
                                    key={item.id}
                                    className="border-border bg-card space-y-3 rounded-xl border p-5"
                                >
                                    <input
                                        type="hidden"
                                        name={`defaults[${index}][role]`}
                                        value={item.role}
                                    />
                                    <Label htmlFor={`default-${item.id}`}>
                                        {item.role.replaceAll('_', ' ')}
                                    </Label>
                                    <textarea
                                        id={`default-${item.id}`}
                                        name={`defaults[${index}][instructions]`}
                                        defaultValue={item.instructions}
                                        className="border-input bg-background min-h-32 w-full rounded-md border px-3 py-2 text-sm"
                                    />
                                </section>
                            ))}
                            <Button type="submit" disabled={processing}>
                                Save global defaults
                            </Button>
                        </>
                    )}
                </Form>
            </main>
        </>
    );
}
