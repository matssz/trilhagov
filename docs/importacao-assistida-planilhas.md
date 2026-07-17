# Importação assistida de planilhas

## Objetivo

O módulo reduz o trabalho inicial de municípios que controlam emendas em Excel
ou arquivos CSV. A planilha nunca altera diretamente o inventário: primeiro o
TrilhaGov apresenta uma conferência por linha e somente depois um gestor ou
editor confirma os registros aptos.

## Fluxo

1. O usuário baixa o modelo CSV e transfere os dados do controle municipal.
2. O sistema reconhece arquivos separados por vírgula ou ponto e vírgula.
3. Datas, valores brasileiros, títulos em português e codificações comuns do
   Excel são normalizados.
4. Cada linha é classificada como apta, duplicada ou inválida.
5. O usuário revisa os motivos e confirma apenas as linhas aptas.
6. As emendas criadas alimentam auditoria, alertas e a Central de Trabalho.

## Proteções

- limite de 2 MB e 500 linhas por lote;
- arquivo original não é armazenado após a leitura;
- lote e linhas sempre vinculados ao município ativo;
- acesso restrito a gestores e editores;
- nenhuma emenda existente é sobrescrita;
- nova verificação de duplicidade no momento da confirmação;
- proteção contra reenvio e múltiplos cliques;
- registro do arquivo, usuário, horário, totais e emendas criadas;
- erros apresentados com o número da linha e mensagem em português.

Uma emenda é considerada duplicada quando possui a mesma identificação, esfera
e exercício no município. Registros inválidos continuam no lote para consulta,
mas não entram no inventário.

## Limite atual

A primeira versão recebe CSV, formato que pode ser exportado pelo Excel,
LibreOffice e Google Planilhas. Arquivos `.xlsx`, mapeamento manual de colunas e
importação de documentos anexos não foram incluídos antes da validação com
planilhas reais de municípios.

O modelo exige os campos básicos e os prazos já obrigatórios no cadastro manual.
O importador não inventa datas, situação, autoria ou valores ausentes.
