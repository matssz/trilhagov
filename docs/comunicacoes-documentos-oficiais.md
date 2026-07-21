# Comunicações e documentos oficiais

## Objetivo

A Central de Comunicações transforma registros municipais já existentes em
documentos formais, preservando a redação usada, a numeração, o protocolo e os
comprovantes de cada envio.

Ela não substitui assinatura, parecer jurídico, sistema oficial de protocolo ou
regra local de competência. O Município deve validar modelos, signatários,
numeração e destinatários antes do uso em produção.

## Modelos municipais

O TrilhaGov instala seis modelos iniciais:

- ofício de comunicação de impedimento;
- notificação administrativa;
- diligência para saneamento;
- despacho administrativo;
- parecer técnico municipal;
- termo de encaminhamento.

O gestor pode criar uma nova versão de cada modelo. A versão anterior fica
preservada para demonstrar qual redação originou cada documento já emitido.

Os campos entre chaves são substituídos apenas por variáveis conhecidas, como
Município, emenda, processo administrativo, destinatário, prazo, contexto e
fundamento informado. O sistema não executa expressões inseridas nos modelos.

## Fluxo

1. Um gestor, editor ou auditor escolhe o modelo e os vínculos do documento.
2. O sistema aproveita dados da emenda e, opcionalmente, de um impedimento,
   diligência ou parecer do Controle Interno.
3. A equipe revisa assunto, corpo, destinatário e prazo enquanto o registro é
   uma minuta.
4. Um gestor confirma a emissão. O TrilhaGov atribui número sequencial, congela
   o conteúdo e calcula o hash SHA-256 da fotografia documental.
5. A equipe registra meio, protocolo, data e comprovante do envio.
6. O recebimento ou a devolução recebe nova evidência e permanece na linha do
   tempo imutável.
7. Uma correção gera outra minuta vinculada à versão anterior.

## Numeração e integridade

Cada tipo possui prefixo próprio e sequência por exercício. O formato inicial é
`PREFIXO-00001/AAAA`. Alterações de prefixo valem somente para novas versões do
modelo e futuras emissões.

O hash cobre conteúdo, destinatário, vínculos, Município, versão do modelo,
número, exercício e responsável pela emissão. Os comprovantes também recebem
hash individual e permanecem em armazenamento privado.

## Validação de campo pendente

Antes da implantação comercial, um Município piloto deve confirmar:

- padrão de numeração e autoridade competente para emissão;
- redação aprovada pela Procuradoria e pelo Controle Interno;
- fluxo de assinatura e protocolo usado pela Câmara;
- meios aceitos para comprovar envio e recebimento;
- prazos e destinatários definidos na legislação local;
- modelos que exigem brasão, cabeçalho ou assinatura eletrônica institucional.
