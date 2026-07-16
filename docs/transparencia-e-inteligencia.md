# Transparência e inteligência gerencial

## Central interna

O painel usa a mesma consulta para indicadores, gráficos e exportação. Os filtros
de exercício, esfera, situação e órgão responsável recalculam todo o recorte.

Indicadores entregues:

- valores previsto, recebido, empenhado, pago e disponível;
- taxas de recebimento e pagamento;
- execução física média e qualidade cadastral;
- prazos vencidos, riscos elevados e prestações aprovadas;
- distribuição por situação, risco, órgão e autor;
- diagnósticos de gargalos e fila de atenção.

A exportação CSV registra usuário, município, filtros e quantidade de registros
na trilha de auditoria. Campos iniciados por caracteres de fórmula recebem proteção
antes de serem enviados à planilha.

## Portal público

O portal nasce desativado e somente um gestor pode publicá-lo ou retirá-lo do ar.
O endereço público usa um identificador aleatório persistente.

Podem ser publicados:

- identificação, exercício, esfera, autoria e objeto;
- órgão responsável e situação do ciclo;
- valores previsto, recebido e pago;
- percentual de execução física.

Não são publicados:

- nomes de usuários e responsáveis internos;
- documentos e observações internas;
- fornecedores, documentos fiscais ou processos de contratação;
- notas e motivos da matriz de risco;
- diligências ou evidências privadas.

Essa separação deve ser preservada nas futuras integrações e exportações.
