<?php

namespace App\Http\Requests;

use App\Models\ParliamentaryAmendment;
use App\Services\CurrentMunicipality;
use App\Services\MunicipalRuleApplicationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ParliamentaryAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $municipalityId = app(CurrentMunicipality::class)->get($this)->id;
        $referenceRule = Rule::unique('parliamentary_amendments', 'reference')
            ->where('municipality_id', $municipalityId)
            ->where('government_sphere', $this->input('government_sphere'))
            ->where('fiscal_year', $this->input('fiscal_year'))
            ->ignore($this->route('emenda'));

        return [
            '_submission_token' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:100', $referenceRule],
            'fiscal_year' => ['required', 'integer', 'between:2000,'.(now()->year + 1)],
            'government_sphere' => ['required', Rule::in(array_keys(ParliamentaryAmendment::governmentSpheres()))],
            'authorship_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::authorshipTypes()))],
            'transfer_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::transferTypes()))],
            'author_name' => ['required', 'string', 'max:255'],
            'author_party' => ['nullable', 'required_if:authorship_type,individual', 'string', 'max:20'],
            'object' => ['required', 'string', 'max:5000'],
            'expense_destination' => ['nullable', 'required_if:government_sphere,municipal', Rule::in(array_keys(ParliamentaryAmendment::expenseDestinations()))],
            'responsible_department' => ['required', 'string', 'max:255'],
            'beneficiary_location' => ['nullable', 'required_if:government_sphere,municipal', 'string', 'max:255'],
            'responsible_user_id' => [
                'nullable',
                'integer',
                Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipalityId)
                    ->whereIn('role', ['manager', 'editor'])),
            ],
            'transferegov_code' => ['nullable', 'required_if:government_sphere,federal', 'string', 'max:100'],
            'legal_instrument' => ['nullable', 'string', 'max:255'],
            'administrative_process' => ['nullable', 'required_if:government_sphere,municipal', 'string', 'max:255'],
            'bank_tracking_type' => ['nullable', 'required_if:government_sphere,municipal', Rule::in(array_keys(ParliamentaryAmendment::bankTrackingTypes()))],
            'bank_account_number' => ['nullable', 'required_if:bank_tracking_type,specific_account', 'string', 'max:100'],
            'funding_source_code' => ['nullable', 'required_if:bank_tracking_type,municipal_direct_codes', 'string', 'max:100'],
            'application_code_fixed' => ['nullable', 'required_if:bank_tracking_type,municipal_direct_codes', 'string', 'max:100'],
            'application_code_variable' => ['nullable', 'required_if:bank_tracking_type,municipal_direct_codes', 'string', 'max:100'],
            'expected_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'received_amount' => ['nullable', 'required_if:status,resource_received,executing,accountability_pending,completed', 'numeric', 'min:0', 'max:9999999999999.99', 'lte:expected_amount'],
            'status' => ['required', Rule::in(array_keys(ParliamentaryAmendment::statuses()))],
            'cancellation_reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:3000'],
            'cancelled_at' => ['nullable', 'required_if:status,cancelled', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'indicated_at' => ['required', 'date', 'before_or_equal:today'],
            'received_at' => ['nullable', 'required_if:status,resource_received,executing,accountability_pending,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'communication_deadline' => ['required', 'date', 'after_or_equal:indicated_at'],
            'communication_completed_at' => ['nullable', 'required_if:status,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'execution_deadline' => ['required', 'date', 'after_or_equal:communication_deadline'],
            'application_deadline' => ['nullable', 'required_if:government_sphere,municipal', 'date', 'after_or_equal:indicated_at'],
            'execution_completed_at' => ['nullable', 'required_if:status,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'accountability_deadline' => ['required', 'date', 'after_or_equal:execution_deadline'],
            'accountability_completed_at' => ['nullable', 'required_if:status,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expense_destination.required_if' => 'Informe se a destinação municipal é custeio ou investimento.',
            'beneficiary_location.required_if' => 'Informe o município ou a localidade beneficiada.',
            'administrative_process.required_if' => 'Informe o número do processo administrativo municipal.',
            'bank_tracking_type.required_if' => 'Informe como os recursos serão rastreados.',
            'bank_account_number.required_if' => 'Informe a conta bancária específica vinculada à emenda.',
            'funding_source_code.required_if' => 'Informe a Fonte de Recursos usada na execução direta.',
            'application_code_fixed.required_if' => 'Informe o Código de Aplicação Fixo usado na execução direta.',
            'application_code_variable.required_if' => 'Informe o Código de Aplicação Variável usado na execução direta.',
            'application_deadline.required_if' => 'Informe o prazo previsto para aplicação dos recursos.',
            'cancellation_reason.required_if' => 'Informe o motivo do cancelamento para publicação.',
            'cancelled_at.required_if' => 'Informe a data do cancelamento.',
            'author_party.required_if' => 'Informe o partido quando a autoria for individual.',
            'transferegov_code.required_if' => 'Informe o código Transferegov para emendas federais.',
            'received_amount.required_if' => 'Informe o valor recebido para a situação selecionada.',
            'received_amount.lte' => 'O valor recebido não pode ser maior que o valor previsto.',
            'responsible_user_id.exists' => 'Selecione um gestor ou editor ativo deste município.',
            'received_at.required_if' => 'Informe a data do recebimento para a situação selecionada.',
            'indicated_at.before_or_equal' => 'A data da indicação não pode estar no futuro.',
            'received_at.after_or_equal' => 'A data do recebimento não pode ser anterior à data da indicação.',
            'received_at.before_or_equal' => 'A data do recebimento não pode estar no futuro.',
            'communication_deadline.after_or_equal' => 'O prazo de comunicação não pode ser anterior à data da indicação.',
            'execution_deadline.after_or_equal' => 'O prazo de execução não pode ser anterior ao prazo de comunicação.',
            'accountability_deadline.after_or_equal' => 'O prazo de prestação de contas não pode ser anterior ao prazo de execução.',
            'communication_completed_at.required_if' => 'Informe quando a comunicação e publicidade foram concluídas.',
            'communication_completed_at.after_or_equal' => 'A conclusão da comunicação não pode ser anterior à data da indicação.',
            'communication_completed_at.before_or_equal' => 'A conclusão da comunicação não pode estar no futuro.',
            'execution_completed_at.required_if' => 'Informe quando a execução foi concluída.',
            'execution_completed_at.after_or_equal' => 'A conclusão da execução não pode ser anterior à data da indicação.',
            'execution_completed_at.before_or_equal' => 'A conclusão da execução não pode estar no futuro.',
            'accountability_completed_at.required_if' => 'Informe quando a prestação de contas foi concluída.',
            'accountability_completed_at.after_or_equal' => 'A conclusão da prestação de contas não pode ser anterior à data da indicação.',
            'accountability_completed_at.before_or_equal' => 'A conclusão da prestação de contas não pode estar no futuro.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $municipality = app(CurrentMunicipality::class)->get($this);
            if ($this->input('bank_tracking_type') === 'municipal_direct_codes'
                && $this->input('transfer_type') !== 'direct_execution') {
                $validator->errors()->add(
                    'bank_tracking_type',
                    'A dispensa de conta individualizada só pode ser usada na execução direta pela Prefeitura.',
                );

                return;
            }
            $amendmentId = $this->route('emenda');
            $current = $amendmentId
                ? $municipality->amendments()->with('regulatoryProfile')->findOrFail($amendmentId)
                : null;
            $errors = app(MunicipalRuleApplicationService::class)->blockingErrors(
                $municipality,
                $this->only([
                    'fiscal_year', 'government_sphere', 'authorship_type',
                    'author_name', 'expected_amount',
                ]),
                $current,
            );

            foreach ($errors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $nullableFields = [
            'author_party', 'transferegov_code', 'received_amount', 'indicated_at',
            'received_at', 'communication_deadline', 'communication_completed_at',
            'execution_deadline', 'execution_completed_at', 'accountability_deadline',
            'accountability_completed_at', 'notes',
            'responsible_user_id',
            'expense_destination', 'beneficiary_location', 'legal_instrument',
            'administrative_process', 'bank_tracking_type', 'bank_account_number',
            'funding_source_code', 'application_code_fixed', 'application_code_variable',
            'application_deadline', 'cancellation_reason', 'cancelled_at',
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
