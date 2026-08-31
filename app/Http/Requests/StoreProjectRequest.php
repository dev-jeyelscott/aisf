<?php

namespace App\Http\Requests;

use App\Services\RepositoryInspector;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    /**
     * Normalize the path before server-side validation and persistence.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'path' => app(RepositoryInspector::class)->normalizePath((string) $this->input('path')),
            'merge_policy' => $this->input('merge_policy', 'human'),
        ]);
    }

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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'path' => ['required', 'string', 'max:2048'],
            'enabled' => ['required', 'boolean'],
            'merge_policy' => ['required', 'string', 'in:human,automatic'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('enabled') && ! $validator->errors()->has('path')) {
                $error = app(RepositoryInspector::class)->validationError((string) $this->input('path'));

                if ($error !== null) {
                    $validator->errors()->add('path', $error);
                }
            }
        }];
    }
}
