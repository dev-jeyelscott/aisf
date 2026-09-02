<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectVerificationRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectVerificationController extends Controller
{
    /**
     * Show authenticated operator-controlled verification policy for one Project.
     */
    public function edit(Project $project): Response
    {
        return Inertia::render('projects/verification', [
            'project' => $project->only([
                'id',
                'title',
            ]),
            'verificationProfiles' => $project->verification_profiles ?? [],
            'maxTimeout' => (int) config(
                'aisf.verification_max_timeout',
                1800,
            ),
            'nativeVerificationEnabled' => (bool) config(
                'aisf.allow_trusted_native_verification',
                false,
            ),
        ]);
    }

    /**
     * Persist validated operator-controlled verification profiles for one Project.
     */
    public function update(
        UpdateProjectVerificationRequest $request,
        Project $project,
    ): RedirectResponse {
        $validated = $request->validated();

        $project->update([
            'verification_profiles' => $validated['verification_profiles'],
        ]);

        return to_route('projects.verification.edit', $project);
    }
}
