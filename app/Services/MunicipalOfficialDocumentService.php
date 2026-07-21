<?php

namespace App\Services;

use App\Models\MunicipalDocumentTemplate;
use App\Models\MunicipalInternalControlReview;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\TechnicalDiligence;
use App\Models\TechnicalImpediment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MunicipalOfficialDocumentService
{
    /** @return Collection<int, MunicipalDocumentTemplate> */
    public function installDefaults(Municipality $municipality, User $user): Collection
    {
        return DB::transaction(function () use ($municipality, $user): Collection {
            return collect($this->defaults())->map(function (array $definition, string $type) use ($municipality, $user) {
                $existing = $municipality->documentTemplates()
                    ->where('document_type', $type)
                    ->orderByDesc('version')
                    ->first();

                return $existing ?: $municipality->documentTemplates()->create([
                    'created_by' => $user->id,
                    'document_type' => $type,
                    'name' => $definition['name'],
                    'prefix' => $definition['prefix'],
                    'version' => 1,
                    'subject_template' => $definition['subject'],
                    'body_template' => $definition['body'],
                    'is_active' => true,
                ]);
            });
        });
    }

    public function revise(MunicipalDocumentTemplate $template, User $user, array $data): MunicipalDocumentTemplate
    {
        return DB::transaction(function () use ($template, $user, $data): MunicipalDocumentTemplate {
            MunicipalDocumentTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $nextVersion = (int) $template->municipality->documentTemplates()
                ->where('document_type', $template->document_type)
                ->max('version') + 1;

            $template->municipality->documentTemplates()
                ->where('document_type', $template->document_type)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return $template->municipality->documentTemplates()->create([
                'created_by' => $user->id,
                'based_on_id' => $template->id,
                'document_type' => $template->document_type,
                'name' => trim($data['name']),
                'prefix' => strtoupper(trim($data['prefix'])),
                'version' => $nextVersion,
                'subject_template' => trim($data['subject_template']),
                'body_template' => trim($data['body_template']),
                'is_active' => true,
            ]);
        });
    }

    /** @return array{subject: string, body: string, variables: array<string, string>} */
    public function render(
        MunicipalDocumentTemplate $template,
        Municipality $municipality,
        ?ParliamentaryAmendment $amendment,
        array $recipient,
        ?TechnicalImpediment $impediment = null,
        ?TechnicalDiligence $diligence = null,
        ?MunicipalInternalControlReview $review = null,
        ?string $context = null,
        ?string $legalBasis = null,
    ): array {
        $sourceContext = $this->sourceContext($impediment, $diligence, $review);
        $variables = [
            'municipio_nome' => $municipality->name,
            'municipio_uf' => $municipality->state,
            'municipio_cnpj' => $municipality->cnpj ?: 'não informado',
            'data_emissao' => now()->format('d/m/Y'),
            'exercicio' => (string) ($amendment?->fiscal_year ?? now()->year),
            'emenda_referencia' => $amendment?->reference ?: 'não vinculada',
            'emenda_objeto' => $amendment?->object ?: 'não vinculada',
            'autor_emenda' => $amendment?->author_name ?: 'não vinculado',
            'processo_administrativo' => $amendment?->administrative_process ?: 'não informado',
            'secretaria_responsavel' => $amendment?->responsible_department ?: 'não informada',
            'destinatario_nome' => trim($recipient['recipient_name']),
            'destinatario_cargo' => filled($recipient['recipient_role'] ?? null) ? trim($recipient['recipient_role']) : 'responsável institucional',
            'destinatario_orgao' => trim($recipient['recipient_entity']),
            'prazo_resposta' => filled($recipient['response_due_at'] ?? null)
                ? date('d/m/Y', strtotime($recipient['response_due_at'])) : 'conforme o fluxo municipal aplicável',
            'contexto' => trim($context ?: $sourceContext ?: 'Contexto a ser complementado pela unidade responsável.'),
            'fundamento' => trim($legalBasis ?: $this->sourceLegalBasis($impediment, $review) ?: 'normas e procedimentos aplicáveis do Município'),
        ];

        return [
            'subject' => $this->replace($template->subject_template, $variables),
            'body' => $this->replace($template->body_template, $variables),
            'variables' => $variables,
        ];
    }

    /** @return array<string, string> */
    public function placeholders(): array
    {
        return [
            'municipio_nome' => 'Nome do Município', 'municipio_uf' => 'UF', 'municipio_cnpj' => 'CNPJ',
            'data_emissao' => 'Data da geração', 'exercicio' => 'Exercício',
            'emenda_referencia' => 'Referência da emenda', 'emenda_objeto' => 'Objeto da emenda',
            'autor_emenda' => 'Autor da emenda', 'processo_administrativo' => 'Processo administrativo',
            'secretaria_responsavel' => 'Secretaria responsável', 'destinatario_nome' => 'Destinatário',
            'destinatario_cargo' => 'Cargo do destinatário', 'destinatario_orgao' => 'Órgão destinatário',
            'prazo_resposta' => 'Prazo de resposta', 'contexto' => 'Contexto ou ocorrência',
            'fundamento' => 'Fundamento informado',
        ];
    }

    private function replace(string $text, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = $value;
        }

        return strtr($text, $replacements);
    }

    private function sourceContext(?TechnicalImpediment $impediment, ?TechnicalDiligence $diligence, ?MunicipalInternalControlReview $review): ?string
    {
        if ($diligence) {
            return $diligence->title.'. '.$diligence->request_details;
        }
        if ($impediment) {
            return $impediment->title.'. '.$impediment->description.($impediment->impact ? ' Impacto: '.$impediment->impact : '');
        }
        if ($review) {
            return $review->reference.': '.$review->summary.($review->recommendations ? ' Recomendações: '.$review->recommendations : '');
        }

        return null;
    }

    private function sourceLegalBasis(?TechnicalImpediment $impediment, ?MunicipalInternalControlReview $review): ?string
    {
        return $review?->legal_basis ?: $impediment?->regulatoryProfile?->legal_review_reference;
    }

    /** @return array<string, array{name: string, prefix: string, subject: string, body: string}> */
    private function defaults(): array
    {
        return [
            'impediment_letter' => [
                'name' => 'Ofício de comunicação de impedimento', 'prefix' => 'OF-IMP',
                'subject' => 'Comunicação de impedimento técnico - Emenda {{emenda_referencia}}',
                'body' => "Ao(À) Senhor(a) {{destinatario_nome}}, {{destinatario_cargo}} do(a) {{destinatario_orgao}}.\n\nO Município de {{municipio_nome}}/{{municipio_uf}} comunica a identificação de impedimento relacionado à Emenda {{emenda_referencia}}, cujo objeto é: {{emenda_objeto}}.\n\nOcorrência: {{contexto}}\n\nFundamento informado: {{fundamento}}.\n\nSolicita-se manifestação ou saneamento até {{prazo_resposta}}, preservado o objeto aprovado e observados os procedimentos municipais aplicáveis.\n\nAtenciosamente,\nMunicípio de {{municipio_nome}}.",
            ],
            'notification' => [
                'name' => 'Notificação administrativa', 'prefix' => 'NOT',
                'subject' => 'Notificação - Emenda {{emenda_referencia}}',
                'body' => "Ao(À) Senhor(a) {{destinatario_nome}}, do(a) {{destinatario_orgao}}.\n\nFica formalmente notificado(a) sobre o seguinte registro relativo à Emenda {{emenda_referencia}}: {{contexto}}\n\nA manifestação deverá ser apresentada até {{prazo_resposta}}, com indicação do processo {{processo_administrativo}} e os documentos comprobatórios pertinentes.\n\nFundamento informado: {{fundamento}}.\n\nMunicípio de {{municipio_nome}}, {{data_emissao}}.",
            ],
            'diligence' => [
                'name' => 'Diligência para saneamento', 'prefix' => 'DIL',
                'subject' => 'Diligência - Emenda {{emenda_referencia}}',
                'body' => "Ao(À) Senhor(a) {{destinatario_nome}}, {{destinatario_cargo}}.\n\nPara continuidade da análise da Emenda {{emenda_referencia}}, solicita-se o atendimento da seguinte diligência: {{contexto}}\n\nA resposta e as evidências deverão ser protocoladas até {{prazo_resposta}}, vinculadas ao processo {{processo_administrativo}}.\n\nUnidade responsável: {{secretaria_responsavel}}.\n\nMunicípio de {{municipio_nome}}, {{data_emissao}}.",
            ],
            'dispatch' => [
                'name' => 'Despacho administrativo', 'prefix' => 'DESP',
                'subject' => 'Despacho - Emenda {{emenda_referencia}}',
                'body' => "Processo: {{processo_administrativo}}\nEmenda: {{emenda_referencia}}\n\nConsiderando {{contexto}}, determino o encaminhamento à unidade competente para análise e providências cabíveis, observando-se {{fundamento}}.\n\nRegistre-se e acompanhe-se o prazo de {{prazo_resposta}}.\n\nMunicípio de {{municipio_nome}}, {{data_emissao}}.",
            ],
            'opinion' => [
                'name' => 'Parecer técnico municipal', 'prefix' => 'PAR',
                'subject' => 'Parecer - Emenda {{emenda_referencia}}',
                'body' => "I. IDENTIFICAÇÃO\nEmenda {{emenda_referencia}}, exercício {{exercicio}}, de autoria de {{autor_emenda}}.\n\nII. OBJETO\n{{emenda_objeto}}\n\nIII. ANÁLISE\n{{contexto}}\n\nIV. FUNDAMENTO INFORMADO\n{{fundamento}}\n\nV. ENCAMINHAMENTO\nSubmeta-se à autoridade competente para decisão e providências, preservando-se os registros e evidências do processo {{processo_administrativo}}.\n\nMunicípio de {{municipio_nome}}, {{data_emissao}}.",
            ],
            'forwarding_term' => [
                'name' => 'Termo de encaminhamento', 'prefix' => 'TER-ENC',
                'subject' => 'Encaminhamento institucional - Emenda {{emenda_referencia}}',
                'body' => "Pelo presente termo, o Município de {{municipio_nome}}/{{municipio_uf}} encaminha ao(à) {{destinatario_orgao}}, aos cuidados de {{destinatario_nome}}, os registros relacionados à Emenda {{emenda_referencia}}.\n\nObjeto: {{emenda_objeto}}\nContexto do encaminhamento: {{contexto}}\nProcesso administrativo: {{processo_administrativo}}\n\nSolicita-se confirmação de recebimento e protocolo.\n\n{{municipio_nome}}, {{data_emissao}}.",
            ],
        ];
    }
}
