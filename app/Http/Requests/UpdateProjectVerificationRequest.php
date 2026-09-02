<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;

class UpdateProjectVerificationRequest extends FormRequest
{
    /**
     * Decode the JSON textarea into structured profile configuration before validation.
     */
    protected function prepareForValidation(): void
    {
        $profiles = $this->input('verification_profiles');

        if (! is_string($profiles)) {
            return;
        }

        if (trim($profiles) === '') {
            $this->merge([
                'verification_profiles' => [],
            ]);

            return;
        }

        try {
            $decoded = json_decode(
                $profiles,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return;
        }

        if (is_array($decoded)) {
            $this->merge([
                'verification_profiles' => $decoded,
            ]);
        }
    }

    /**
     * Allow only authenticated route middleware to decide access to verification policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the bounded structured Project verification profile contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'verification_profiles' => [
                'required',
                'array',
                'max:20',
            ],
            'verification_profiles.*' => [
                'required',
                'array:driver,compose_file,compose_project,service,user,command,timeout',
            ],
            'verification_profiles.*.driver' => [
                'required',
                'string',
                'in:native,docker_compose_exec',
            ],
            'verification_profiles.*.compose_file' => [
                'nullable',
                'string',
                'max:255',
            ],
            'verification_profiles.*.compose_project' => [
                'nullable',
                'string',
                'max:63',
            ],
            'verification_profiles.*.service' => [
                'nullable',
                'string',
                'max:64',
            ],
            'verification_profiles.*.user' => [
                'nullable',
                'string',
                'max:31',
            ],
            'verification_profiles.*.command' => [
                'required',
                'array',
                'min:1',
                'max:32',
            ],
            'verification_profiles.*.command.*' => [
                'required',
                'string',
                'max:500',
            ],
            'verification_profiles.*.timeout' => [
                'required',
                'integer',
                'min:1',
                'max:'.max(
                    1,
                    (int) config('aisf.verification_max_timeout', 1800),
                ),
            ],
        ];
    }

    /**
     * Enforce profile names, Docker-only fields, path confinement syntax, and shell exclusion.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $profiles = $this->input('verification_profiles');

                if (! is_array($profiles)) {
                    return;
                }

                foreach ($profiles as $name => $profile) {
                    if (
                        ! is_string($name)
                        || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) !== 1
                    ) {
                        $validator->errors()->add(
                            'verification_profiles',
                            'Verification profile names must use lowercase letters, numbers, underscores, or hyphens.',
                        );

                        continue;
                    }

                    if (! is_array($profile)) {
                        continue;
                    }

                    $driver = $profile['driver'] ?? null;
                    $command = $profile['command'] ?? null;

                    if (is_array($command) && isset($command[0]) && is_string($command[0])) {
                        $executable = strtolower(
                            basename(
                                str_replace('\\', '/', $command[0]),
                            ),
                        );

                        if (in_array(
                            $executable,
                            [
                                'sh',
                                'bash',
                                'zsh',
                                'fish',
                                'cmd',
                                'cmd.exe',
                                'powershell',
                                'powershell.exe',
                                'pwsh',
                            ],
                            true,
                        )) {
                            $validator->errors()->add(
                                "verification_profiles.{$name}.command",
                                'Verification profiles may not execute a command shell.',
                            );
                        }

                        foreach ($command as $argument) {
                            if (is_string($argument) && str_contains($argument, "\0")) {
                                $validator->errors()->add(
                                    "verification_profiles.{$name}.command",
                                    'Verification command arguments may not contain null bytes.',
                                );
                            }
                        }
                    }

                    if ($driver === 'native') {
                        foreach ([
                            'compose_file',
                            'compose_project',
                            'service',
                            'user',
                        ] as $dockerOnlyKey) {
                            if (filled($profile[$dockerOnlyKey] ?? null)) {
                                $validator->errors()->add(
                                    "verification_profiles.{$name}.{$dockerOnlyKey}",
                                    'Native verification profiles may not configure Docker fields.',
                                );
                            }
                        }

                        continue;
                    }

                    if ($driver !== 'docker_compose_exec') {
                        continue;
                    }

                    foreach ([
                        'compose_file',
                        'compose_project',
                        'service',
                        'user',
                    ] as $requiredKey) {
                        if (! filled($profile[$requiredKey] ?? null)) {
                            $validator->errors()->add(
                                "verification_profiles.{$name}.{$requiredKey}",
                                'This field is required for Docker verification profiles.',
                            );
                        }
                    }

                    $composeFile = $profile['compose_file'] ?? null;

                    if (is_string($composeFile)) {
                        $normalized = str_replace('\\', '/', trim($composeFile));

                        if (
                            $normalized === ''
                            || str_contains($normalized, "\0")
                            || str_starts_with($normalized, '/')
                            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
                            || in_array('..', explode('/', $normalized), true)
                            || ! in_array(
                                strtolower(pathinfo($normalized, PATHINFO_EXTENSION)),
                                ['yml', 'yaml'],
                                true,
                            )
                        ) {
                            $validator->errors()->add(
                                "verification_profiles.{$name}.compose_file",
                                'The Compose definition must be a relative .yml or .yaml file inside the trusted verification definition directory.',
                            );
                        }
                    }

                    $composeProject = $profile['compose_project'] ?? null;

                    if (
                        is_string($composeProject)
                        && preg_match(
                            '/^[a-z0-9][a-z0-9_-]{0,62}$/',
                            $composeProject,
                        ) !== 1
                    ) {
                        $validator->errors()->add(
                            "verification_profiles.{$name}.compose_project",
                            'The Compose project name is invalid.',
                        );
                    }

                    $service = $profile['service'] ?? null;

                    if (
                        is_string($service)
                        && preg_match(
                            '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/',
                            $service,
                        ) !== 1
                    ) {
                        $validator->errors()->add(
                            "verification_profiles.{$name}.service",
                            'The Compose service name is invalid.',
                        );
                    }

                    $user = $profile['user'] ?? null;

                    if (
                        is_string($user)
                        && preg_match('/^\d+(?::\d+)?$/', $user) !== 1
                    ) {
                        $validator->errors()->add(
                            "verification_profiles.{$name}.user",
                            'The verifier user must be a numeric UID or UID:GID.',
                        );
                    }
                }
            },
        ];
    }
}
