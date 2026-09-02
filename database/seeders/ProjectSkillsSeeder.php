<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\ProjectSkill;
use App\Services\ProjectAgentProvisioner;
use Illuminate\Database\Seeder;

class ProjectSkillsSeeder extends Seeder
{
    /**
     * Seed the default PM, Coder, and QA skills for every existing Project.
     */
    public function run(ProjectAgentProvisioner $projectAgentProvisioner): void
    {
        foreach (Project::query()->lazyById() as $project) {
            $projectAgentProvisioner->ensureFor($project);

            $this->seedProject($project);
        }
    }

    /**
     * Create missing role skills for one Project and assign them to the matching Agent.
     */
    private function seedProject(Project $project): void
    {
        foreach ($this->skillDefinitions() as $role => $definitions) {
            $agent = $project->agents()
                ->where('role', $role)
                ->sole();

            $skills = [];

            foreach ($definitions as $definition) {
                $skills[] = $project->skills()->firstOrCreate(
                    [
                        'name' => $definition['name'],
                    ],
                    [
                        'description' => $definition['description'],
                        'instructions' => $definition['instructions'],
                        'enabled' => true,
                    ],
                );
            }

            $this->attachMissingSkills($agent, $skills);
        }
    }

    /**
     * Append newly seeded Skills without replacing or reordering existing Agent assignments.
     *
     * @param  list<ProjectSkill>  $skills
     */
    private function attachMissingSkills(ProjectAgent $agent, array $skills): void
    {
        $assignedSkillIds = array_map(
            'intval',
            $agent->skills()->pluck('project_skills.id')->all(),
        );

        $nextPosition = ((int) ($agent->skills()
            ->max('project_agent_project_skill.position') ?? 0)) + 1;

        foreach ($skills as $skill) {
            $skillId = (int) $skill->getKey();

            if (in_array($skillId, $assignedSkillIds, true)) {
                continue;
            }

            $agent->skills()->attach($skillId, [
                'position' => $nextPosition,
            ]);

            $assignedSkillIds[] = $skillId;
            $nextPosition++;
        }
    }

    /**
     * Return the default reusable Skill definitions grouped by AISF Agent role.
     *
     * @return array<string, list<array{name: string, description: string, instructions: string}>>
     */
    private function skillDefinitions(): array
    {
        return [
            'project_manager' => [
                [
                    'name' => 'Requirements Analysis and Task Decomposition',
                    'description' => 'Transforms a WorkRequest into clear, implementation-ready, independently verifiable Tasks.',
                    'instructions' => 'Inspect the available repository context and understand the requested outcome before planning. Break the WorkRequest into the smallest independently verifiable Tasks that are useful to the Coder. Every Task must contain a precise objective, implementation specification, measurable acceptance criteria, relevant verification commands, browser verification steps when user-visible behavior changes, and only genuine dependencies. Do not implement code, invent repository facts, or expand the requested scope.',
                ],
                [
                    'name' => 'Acceptance Criteria and Verification Planning',
                    'description' => 'Defines objective acceptance criteria and practical verification requirements before implementation.',
                    'instructions' => 'Translate requirements into observable and measurable acceptance criteria. Specify focused automated verification and manual browser verification whenever the behavior is user-visible. Clearly separate required outcomes from assumptions and optional improvements. Do not consider a Task complete based only on an implementation summary or unverified claims.',
                ],
                [
                    'name' => 'Workflow and Handoff Coordination',
                    'description' => 'Coordinates Task readiness, dependencies, and durable handoffs without bypassing AISF workflow authority.',
                    'instructions' => 'Respect AISF durable workflow state, dependency gates, and role boundaries. Hand off only ready Tasks to the Coder and preserve the Task scope, acceptance criteria, repository context, and relevant evidence across handoffs. Do not bypass the Coder or QA stages, grant additional authority, perform implementation work on behalf of another role, or treat provider conversation state as authoritative system state.',
                ],
            ],

            'coder' => [
                [
                    'name' => 'Repository-Aware Implementation',
                    'description' => 'Implements the smallest production-ready change using the repository as the primary technical authority.',
                    'instructions' => 'Inspect applicable project rules, existing implementation, tests, schema, configuration, and nearby conventions before editing. Identify the root cause or exact implementation boundary, then make the smallest correct, secure, maintainable, and testable change that satisfies the Task. Reuse existing architecture and dependencies. Avoid unrelated refactors, speculative abstractions, unnecessary packages, and changes to APIs, schema, business rules, or UX outside the approved scope.',
                ],
                [
                    'name' => 'Verification and Evidence',
                    'description' => 'Produces focused tests and durable implementation evidence for every candidate handed to QA.',
                    'instructions' => 'Add or update focused tests for every changed behavior and important applicable failure path. Run the Task verification commands and relevant static analysis, formatting, type checks, builds, or browser verification required by the change. Record an accurate summary, changed files, executed validation results, browser verification results when applicable, assumptions, and remaining risks. Never claim that a command, test, browser step, or check passed unless it was actually executed successfully.',
                ],
                [
                    'name' => 'Git Candidate Discipline',
                    'description' => 'Preserves AISF Git ownership and candidate integrity throughout implementation and repair cycles.',
                    'instructions' => 'Produce the implementation candidate only inside the AISF-managed Task workspace and preserve the existing Git lifecycle. Do not commit, merge, rewrite protected history, or bypass AISF Git controls before QA authorization. Save durable implementation evidence before handing the candidate to QA. When QA requests changes, address the documented findings against the current Task without introducing unrelated work, then produce updated verification evidence for the repaired candidate.',
                ],
            ],

            'qa' => [
                [
                    'name' => 'Independent Candidate Review',
                    'description' => 'Independently validates the current Coder candidate against the Task contract and repository standards.',
                    'instructions' => 'Review the current immutable candidate independently against the Task objective, implementation specification, acceptance criteria, and available evidence. Inspect the actual changed implementation instead of trusting the Coder summary. Evaluate correctness, security, data integrity, maintainability, regression risk, repository conventions, and scope compliance where applicable. A code-producing Agent must never approve its own candidate.',
                ],
                [
                    'name' => 'Regression and Browser Verification',
                    'description' => 'Executes focused automated, regression, failure-path, and browser verification appropriate to the candidate.',
                    'instructions' => 'Run the relevant automated tests and Task verification commands against the current candidate. Verify important failure paths, edge cases, authorization or project-isolation boundaries, data integrity, and regressions when applicable. Execute specified browser verification for user-visible behavior and record the observed result. Clearly distinguish checks that were actually executed from assumptions or checks that could not be completed.',
                ],
                [
                    'name' => 'Defect Reporting and Repair Handoff',
                    'description' => 'Produces actionable QA decisions and sends failed candidates through the bounded repair workflow.',
                    'instructions' => 'Use only the supported review outcomes approved or changes_requested. Approve only when the current candidate satisfies the Task acceptance criteria and the verification evidence supports that conclusion. When defects exist, request changes with specific actionable findings that explain the observed problem, expected behavior, evidence, and impact, then hand the repair back to the Coder. Do not modify the candidate yourself, silently waive failed criteria, or bypass the AISF acceptance gate.',
                ],
            ],
        ];
    }
}
