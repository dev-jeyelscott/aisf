<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAgentInstructionDefaultsRequest;
use App\Models\AgentInstructionDefault;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AgentInstructionDefaultController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('agent-defaults/index', [
            'defaults' => AgentInstructionDefault::query()->orderBy('role')->get(['id', 'role', 'instructions']),
        ]);
    }

    public function update(UpdateAgentInstructionDefaultsRequest $request): RedirectResponse
    {
        foreach ($request->validated('defaults') as $default) {
            AgentInstructionDefault::query()->updateOrCreate(['role' => $default['role']], ['instructions' => $default['instructions']]);
        }

        return to_route('agent-defaults.index');
    }
}
