<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentInstructionDefaultsRequest extends FormRequest
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
            'defaults' => ['required', 'array'],
            'defaults.*.role' => ['required', 'string', 'max:100', 'distinct'],
            'defaults.*.instructions' => ['required', 'string', 'max:20000'],
        ];
    }
}
