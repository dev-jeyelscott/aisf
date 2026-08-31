<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectAgentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'identity' => ['nullable', 'string'],
            'harness' => ['required', 'in:codex,claude'], 'model' => ['nullable', 'string', 'max:255'],
            'settings' => ['nullable', 'string', 'max:5000', 'json'], 'default_context' => ['nullable', 'string'],
            'workflow_instructions' => ['nullable', 'string'], 'enabled' => ['required', 'boolean'],
            'skill_ids' => ['nullable', 'array'], 'skill_ids.*' => ['integer'],
            'skill_positions' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');
            if (! $project instanceof Project || $validator->errors()->has('skill_ids')) {
                return;
            }
            $skillIds = $this->input('skill_ids', []);
            if ($project->skills()->whereKey($skillIds)->count() !== count(array_unique($skillIds))) {
                $validator->errors()->add('skill_ids', 'Skills must belong to this project.');
            }
        }];
    }
}
