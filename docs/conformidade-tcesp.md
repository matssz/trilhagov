# Matriz de conformidade TCESP

## Finalidade

O módulo transforma o **Manual de Emendas Parlamentares Impositivas Municipais**
do Tribunal de Contas do Estado de São Paulo, publicado em julho de 2026, em um
roteiro operacional por emenda. Ele é aplicável somente quando:

- o município cadastrado pertence ao Estado de São Paulo; e
- a esfera da emenda é `Municipal`.

O Município de São Paulo (código IBGE 3550308) é excluído, pois possui Tribunal
de Contas próprio e não integra a jurisdição municipal do TCESP.

Fonte oficial: <https://www.tce.sp.gov.br/publicacoes/manual-emendas-parlamentares-impositivas-municipais>

O módulo é um apoio à conferência e à organização de evidências. Não emite
parecer jurídico, não certifica conformidade e não representa validação do TCESP.

## Versão inicial

A matriz `tcesp-manual-2026-07` contém 24 verificações organizadas em:

1. Base normativa.
2. Objeto e orçamento.
3. Metas e viabilidade.
4. Plano de trabalho.
5. Beneficiário e saúde.
6. Impedimentos técnicos.
7. Transparência e Audesp.
8. Prestação de contas e controle interno.

Cada item pode ser classificado como `Pendente`, `Atendido`, `Não atendido` ou
`Não se aplica`. Para marcar atendimento, é obrigatório descrever a evidência ou
vincular um documento da própria emenda. Situações negativas e dispensas exigem
justificativa textual.

Toda revisão registra usuário, data, versão da matriz e alteração no histórico
imutável da emenda. Requisições repetidas usam token de uso único e não geram
revisões ou eventos duplicados.

## Plano de trabalho e admissibilidade

Emendas municipais paulistas também possuem um fluxo estruturado de planejamento:

- identificação do beneficiário ou órgão executor, CNPJ e contato;
- objeto detalhado, justificativa e necessidade pública;
- metas física e finalística;
- programa, ação orçamentária, plano de aplicação e memória de cálculo;
- operação e manutenção futura;
- controles condicionais de saúde, engenharia, licença ambiental e PCA;
- cronograma físico-financeiro com entregas, datas e valores;
- bloqueio do envio quando o total das etapas diverge do valor da emenda;
- parecer de admissibilidade com sete critérios e conclusão fundamentada;
- devolução para ajustes e reenvio como nova revisão;
- cópia histórica imutável de cada versão analisada;
- geração de PDF operacional com plano, cronograma e pareceres;
- ações automáticas na Central de Trabalho durante todas as fases.

Editores e gestores podem preparar o plano. Durante a análise ele permanece
bloqueado, e somente um gestor pode emitir o parecer. A aprovação exige que não
exista critério marcado como não atendido. Devolução ou rejeição exigem ao menos
uma não conformidade identificada; a devolução também exige instruções de ajuste.

## Impedimentos, diligências e remanejamento

O fluxo operacional permite registrar uma constatação técnica, classificá-la como
temporária ou insuperável, atribuir responsável, prazo e evidência e abrir diligências
com resposta e protocolo. Impedimentos insuperáveis podem originar uma proposta de
remanejamento, preservando o objeto original. A decisão exige perfil de gestor,
fundamentação e referência formal.

Prazos vencidos entram nos alertas de integridade e na Central de Trabalho. Aprovar
um remanejamento não altera automaticamente os dados originais da emenda nem substitui
o procedimento legal aplicável no município.

## Limites e próximos requisitos

Ainda precisam ser construídos e validados com procuradores, controladores,
contadores e equipes legislativas municipais:

- aplicação automática da parametrização vigente nos prazos e validações de cada emenda;
- cadastro e exportação no formato exigido pelo sistema Audesp;
- conciliação dos códigos contábeis, conta bancária e regra Audesp 47.4.63;
- publicação dos campos do artigo 3º da Resolução TCESP nº 17/2025 no portal público;
- relatórios periódicos para Câmara, controle interno e prestação de contas;
- regras específicas para saúde e organizações da sociedade civil;
- revisão jurídica do conteúdo antes de qualquer promessa comercial de conformidade.

As regras da matriz ficam em `app/Services/TcespComplianceFramework.php`. Uma
alteração normativa deve criar nova versão, preservando as revisões históricas da
versão anterior.

A parametrização de Lei Orgânica, Regimento, PPA, LDO, LOA, limites, saúde,
impedimentos, transparência, rastreabilidade e revisão jurídica foi entregue no
módulo de Normas Municipais. O desenho e seus limites estão documentados em
`docs/parametrizacao-normativa-municipal.md`.
