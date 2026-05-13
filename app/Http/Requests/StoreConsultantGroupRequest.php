<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsultantGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isAdmin() || $this->user()->hasAccess('consultant_groups.create');
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
                'required',
                'string',
                'max:255',
                Rule::unique('consultant_groups', 'name')->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string|max:1000',
            'active' => 'sometimes|boolean',
            'consultant_ids' => 'required|array|min:1',
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
            'consultant_ids.required' => 'É necessário adicionar pelo menos um consultor ao grupo.',
            'consultant_ids.min' => 'É necessário adicionar pelo menos um consultor ao grupo.',
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
        // Garantir que só consultores e parceiros (parceiro_admin) podem entrar
        // num grupo de consultor. Reflete a regra de bcb5c5f, onde parceiros
        // são tratados como consultores nas alocações.
        if ($this->has('consultant_ids') && is_array($this->consultant_ids)) {
            $validConsultantIds = \App\Models\User::whereIn('type', ['consultor', 'parceiro_admin'])
                ->whereIn('id', $this->consultant_ids)->pluck('id')->toArray();

            $this->merge([
                'consultant_ids' => $validConsultantIds,
            ]);
        }
    }
}

