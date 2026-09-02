import { Form, Head, Link } from '@inertiajs/react';
import ProjectVerificationController from '@/actions/App/Http/Controllers/ProjectVerificationController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/projects';

type VerificationProfile = {
    driver: 'native' | 'docker_compose_exec';
    compose_file?: string;
    compose_project?: string;
    service?: string;
    user?: string;
    command: string[];
    timeout: number;
};

type Props = {
    project: {
        id: number;
        title: string;
    };
    verificationProfiles: Record<string, VerificationProfile>;
    maxTimeout: number;
    nativeVerificationEnabled: boolean;
};

/**
 * Render authenticated operator controls for Project host verification policy.
 */
export default function ProjectVerification({
    project,
    verificationProfiles,
    maxTimeout,
    nativeVerificationEnabled,
}: Props) {
    return (
        <>
            <Head title={`Verification · ${project.title}`} />

            <div className="mx-auto w-full max-w-3xl p-4 md:p-8">
                <Button asChild variant="ghost">
                    <Link href={index()}>Back to Projects</Link>
                </Button>

                <h1 className="mt-4 text-2xl font-semibold tracking-tight">
                    Project Verification
                </h1>

                <p className="text-muted-foreground mt-1">
                    Configure the only host-controlled verification profiles
                    that sandboxed Agents may request.
                </p>

                <div className="border-border bg-card mt-6 rounded-xl border p-6">
                    <Form
                        {...ProjectVerificationController.update.form(project)}
                        className="grid gap-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <label
                                        htmlFor="verification_profiles"
                                        className="text-sm font-medium"
                                    >
                                        Verification profiles
                                    </label>

                                    <textarea
                                        id="verification_profiles"
                                        name="verification_profiles"
                                        defaultValue={JSON.stringify(
                                            verificationProfiles,
                                            null,
                                            4,
                                        )}
                                        spellCheck={false}
                                        className="border-input bg-background min-h-[28rem] rounded-md border p-3 font-mono text-sm"
                                        aria-describedby="verification-help"
                                    />

                                    <p
                                        id="verification-help"
                                        className="text-muted-foreground text-sm"
                                    >
                                        Agents may select only a profile name.
                                        They cannot provide commands, Docker
                                        options, container names, environment
                                        variables, or shell input. Maximum
                                        timeout: {maxTimeout} seconds.
                                    </p>

                                    <InputError
                                        message={errors.verification_profiles}
                                    />
                                </div>

                                <div className="border-border bg-muted/30 rounded-lg border p-4 text-sm">
                                    <p className="font-medium">
                                        Security boundary
                                    </p>
                                    <p className="text-muted-foreground mt-1">
                                        Docker Compose definitions must live in
                                        the AISF verification-definition
                                        directory, never inside the managed
                                        Project repository.
                                    </p>

                                    {!nativeVerificationEnabled && (
                                        <p className="text-muted-foreground mt-2">
                                            Native host verification is
                                            currently disabled. Docker-backed
                                            verification remains available.
                                        </p>
                                    )}
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-fit"
                                >
                                    Save verification profiles
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

ProjectVerification.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
