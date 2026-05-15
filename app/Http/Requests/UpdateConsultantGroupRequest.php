<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsultantGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isAdmin()
            || $this->user()->hasAccess('groups.manage')
            || $this->user()->hasAccess('consultant_groups.update');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('consultant_groups', 'name')
                    ->ignore($this->route('consultant_group'))
                    ->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string|max:1000',
            'active' => 'sometimes|boolean',
            'consultant_ids' => 'sometimes|array|min:1',
            'consultant_ids.*' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome do grupo é obrigatório.',
            'name.unique' => 'Já existe um grupo com este nome.',
            'name.max' => 'O nome do grupo não pode ter mais de 255 caracteres.',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
            'consultant_ids.min' => 'É necessário ter pelo menos um consultor no grupo.',
            'consultant_ids.array' => 'Os consultores devem ser enviados em formato de lista.',
            'consultant_ids.*.exists' => 'Um ou mais consultores selecionados não existem.',
            'consultant_ids.*.integer' => 'ID de consultor inválido.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Consultores e parceiros (parceiro_admin) podem entrar num grupo —
        // ver bcb5c5f.
        if ($this->has('consultant_ids') && is_array($this->consultant_ids)) {
            $validConsultantIds = \App\Models\User::whereIn('type', ['consultor', 'parceiro_admin'])
                ->whereIn('id', $this->consultant_ids)->pluck('id')->toArray();

            $this->merge([
                'consultant_ids' => $validConsultantIds,
            ]);
        }
    }
}

