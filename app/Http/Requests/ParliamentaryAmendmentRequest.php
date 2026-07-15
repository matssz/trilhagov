<?php

namespace App\Http\Requests;

use App\Models\ParliamentaryAmendment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParliamentaryAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $municipalityId = $this->user()->municipalities()->value('municipalities.id');
        $referenceRule = Rule::unique('parliamentary_amendments', 'reference')
            ->where('municipality_id', $municipalityId)
            ->where('government_sphere', $this->input('government_sphere'))
            ->where('fiscal_year', $this->input('fiscal_year'))
            ->ignore($this->route('emenda'));

        return [
            'reference' => ['required', 'string', 'max:100', $referenceRule],
            'fiscal_year' => ['required', 'integer', 'between:2000,'.(now()->year + 1)],
            'government_sphere' => ['required', Rule::in(array_keys(ParliamentaryAmendment::governmentSpheres()))],
            'authorship_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::authorshipTypes()))],
            'transfer_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::transferTypes()))],
            'author_name' => ['required', 'string', 'max:255'],
            'author_party' => ['nullable', 'string', 'max:20'],
            'object' => ['required', 'string', 'max:5000'],
            'responsible_department' => ['required', 'string', 'max:255'],
            'transferegov_code' => ['nullable', 'string', 'max:100'],
            'expected_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'received_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'status' => ['required', Rule::in(array_keys(ParliamentaryAmendment::statuses()))],
            'indicated_at' => ['nullable', 'date'],
            'received_at' => ['nullable', 'date'],
            'communication_deadline' => ['nullable', 'date'],
            'communication_completed_at' => ['nullable', 'date'],
            'execution_deadline' => ['nullable', 'date'],
            'execution_completed_at' => ['nullable', 'date'],
            'accountability_deadline' => ['nullable', 'date'],
            'accountability_completed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reference' => 'identificação da emenda',
            'fiscal_year' => 'exercício',
            'government_sphere' => 'esfera',
            'authorship_type' => 'tipo de autoria',
            'transfer_type' => 'modalidade de transferência',
            'author_name' => 'autor da emenda',
            'object' => 'objeto',
            'responsible_department' => 'órgão responsável',
            'expected_amount' => 'valor previsto',
            'received_amount' => 'valor recebido',
            'status' => 'situação',
        ];
    }

    protected function prepareForValidation(): void
    {
        $nullableFields = [
            'author_party', 'transferegov_code', 'received_amount', 'indicated_at',
            'received_at', 'communication_deadline', 'communication_completed_at',
            'execution_deadline', 'execution_completed_at', 'accountability_deadline',
            'accountability_completed_at', 'notes',
        ];
        $data = [
            'reference' => trim((string) $this->input('reference')),
            'author_name' => trim((string) $this->input('author_name')),
            'object' => trim((string) $this->input('object')),
            'responsible_department' => trim((string) $this->input('responsible_department')),
        ];

        foreach ($nullableFields as $field) {
            $data[$field] = $this->input($field) === '' ? null : $this->input($field);
        }

        $this->merge($data);
    }
}
