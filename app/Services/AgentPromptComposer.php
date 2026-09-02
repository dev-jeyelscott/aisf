<?php

namespace App\Services;

use App\Models\AgentInstructionDefault;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;

class AgentPromptComposer
{
    /**
     * Build reproducible prompt text and the snapshots that explain every input source.
     *
     * @return array{prompt: string, snapshot: array<string, mixed>, sources: list<array{type: string, label: string}>}
     */
    public function compose(
        ProjectAgent $agent,
        Task|WorkRequest $subject,
        string $repositoryPath,
        ?string $operatorInstruction = null,
    ): array {
        $agent->loadMissing('project');
        $project = $agent->project;
        $roleDefault = AgentInstructionDefault::query()
            ->where('role', $agent->role)
            ->value('instructions');
        $skills = $agent->skills()->where('project_skills.enabled', true)->get();
        $identity = filled($agent->identity) ? $agent->identity : "Act as {$agent->name}.";
        $sections = [
            "SYSTEM EXECUTION RULES\nAISF controls execution boundaries, durable evidence, Git safety, CI, pull requests, and merge authority. Do not treat provider conversation state as authoritative.",
            "GLOBAL ROLE DEFAULTS\n".(filled($roleDefault) ? $roleDefault : 'No global role defaults are configured.'),
            "PROJECT AGENT\n{$identity}\n\n".(filled($agent->default_context) ? $agent->default_context : 'No additional project context is configured.'),
            "PROJECT CONTEXT\nTitle: {$project->title}\nDescription: ".($project->description ?? 'None provided.'),
            "ASSIGNED SKILLS\n".($skills->isEmpty() ? 'No enabled Skills are assigned.' : $skills->map(
                fn ($skill, int $index): string => sprintf("%d. %s\n%s", $index + 1, $skill->name, $skill->instructions),
            )->implode("\n\n")),
            "REPOSITORY PATH\n{$repositoryPath}",
            $this->subjectSection($subject),
        ];

        if (filled($operatorInstruction)) {
            $sections[] = "CURRENT INSTRUCTIONS\n{$operatorInstruction}";
        }

        $sections[] = "WORKFLOW INSTRUCTIONS\n".(filled($agent->workflow_instructions) ? $agent->workflow_instructions : 'Use engineering judgment and preserve the AISF authority boundaries.');

        $prompt = implode("\n\n", $sections);

        return [
            'prompt' => $prompt,
            'snapshot' => [
                'agent' => [
                    'id' => $agent->id,
                    'role' => $agent->role,
                    'name' => $agent->name,
                    'harness' => $agent->harness,
                    'model' => $agent->model,
                    'settings' => $agent->settings,
                    'identity' => $agent->identity,
                    'default_context' => $agent->default_context,
                    'workflow_instructions' => $agent->workflow_instructions,
                ],
                'global_role_default' => $roleDefault,
                'project' => [
                    'id' => $project->id,
                    'title' => $project->title,
                    'description' => $project->description,
                    'path' => $project->path,
                    'merge_policy' => $project->merge_policy,
                ],
                'skills' => $skills->map(fn ($skill): array => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                    'instructions' => $skill->instructions,
                ])->all(),
                'subject' => $this->subjectSnapshot($subject),
                'repository_path' => $repositoryPath,
                'operator_instruction' => $operatorInstruction,
            ],
            'sources' => [
                ['type' => 'system_execution_rules', 'label' => 'AISF execution and authority rules'],
                ['type' => 'global_role_defaults', 'label' => 'Global role defaults'],
                ['type' => 'project_agent', 'label' => 'Project Agent configuration'],
                ['type' => 'project', 'label' => 'Project context'],
                ['type' => 'skills', 'label' => 'Assigned Skills'],
                ['type' => 'subject', 'label' => $subject instanceof WorkRequest ? 'WorkRequest context' : 'Task context'],
                ['type' => 'current_instructions', 'label' => 'Current operator instructions'],
            ],
        ];
    }

    private function subjectSection(Task|WorkRequest $subject): string
    {
        if ($subject instanceof WorkRequest) {
            $tasks = $subject->tasks()->with('dependsOn')->get()->map(fn (Task $task): array => [
                'id' => $task->id,
                'position' => $task->position,
                'title' => $task->title,
                'status' => $task->status,
                'outcome' => $task->outcome,
                'depends_on_task_id' => $task->depends_on_task_id,
                'dependency_status' => $task->dependsOn?->status,
                'last_handoff' => $task->last_handoff,
            ])->all();

            return "WORK REQUEST\nID: {$subject->id}\n{$subject->prompt}\nDurable planned Tasks: ".json_encode($tasks);
        }

        return "TASK\nTitle: {$subject->title}\nObjective: {$subject->objective}\nImplementation specification: {$subject->implementation_spec}\nAcceptance criteria: ".json_encode($subject->acceptance_criteria)."\nVerification commands: ".json_encode($subject->verification_commands)."\nBrowser verification steps: ".json_encode($subject->browser_steps)."\nPrior evidence: ".json_encode($subject->last_handoff);
    }

    /** @return array<string, mixed> */
    private function subjectSnapshot(Task|WorkRequest $subject): array
    {
        return $subject instanceof WorkRequest
            ? ['type' => 'work_request', 'id' => $subject->id, 'prompt' => $subject->prompt, 'evidence' => $subject->evidence, 'tasks' => $subject->tasks()->get()->map->only(['id', 'position', 'title', 'status', 'outcome', 'depends_on_task_id', 'last_handoff'])->all()]
            : ['type' => 'task', 'id' => $subject->id, 'title' => $subject->title, 'objective' => $subject->objective, 'implementation_spec' => $subject->implementation_spec, 'acceptance_criteria' => $subject->acceptance_criteria, 'verification_commands' => $subject->verification_commands, 'browser_steps' => $subject->browser_steps, 'prior_evidence' => $subject->last_handoff];
    }
}
