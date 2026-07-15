# Confiabilidade e atualizações

## Atualizar sistema

O ícone de atualização no rodapé do menu executa um reset individual e seguro.
Ele existe para recuperar a interface quando uma atualização, uma aba antiga ou
um formulário já processado deixa o navegador com estado temporário obsoleto.

O processo remove:

- tokens de formulários emitidos antes da atualização;
- campos e mensagens de validações anteriores;
- redirecionamentos temporários da sessão;
- cache HTTP mantido pelo navegador para o domínio.

O processo preserva:

- autenticação do usuário;
- município ativo, quando o vínculo continua válido;
- usuários, emendas, documentos e registros de auditoria;
- arquivos privados;
- cache compartilhado da aplicação.

Se o município salvo não for mais válido, a aplicação ativa o único vínculo
disponível ou solicita uma nova seleção. Uma conta sem município válido é
desconectada por segurança.

## Prevenção automática

Respostas autenticadas usam `Cache-Control: no-store`, `Pragma: no-cache` e
expiração imediata. Isso reduz o reaproveitamento de HTML com token CSRF antigo.
Os arquivos de CSS e JavaScript gerados pelo Vite possuem nomes versionados, de
modo que uma nova compilação aponta para novos arquivos.

## Limites

O reset individual não substitui procedimentos de implantação do servidor e não
apaga o cache global. Limpar o cache global a partir de um botão de usuário
afetaria todas as sessões e poderia aumentar a carga da aplicação.

O cabeçalho `Clear-Site-Data` exige HTTPS em produção. Em navegadores que não o
suportam, a renovação da sessão e os cabeçalhos `no-store` continuam funcionando.
