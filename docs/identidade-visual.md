# Identidade visual - TrilhaGov

Este documento registra o padrão visual para que novos módulos mantenham a mesma
linguagem sem depender de decisões repetidas a cada tela.

## Marca

- Símbolo oficial do projeto: `public/images/trilhagov-symbol.svg`.
- Nome exibido: **TrilhaGov**.
- Assinatura do produto: **Portal de Emendas**.
- O símbolo não deve ser distorcido, girado ou receber outras cores.

## Cores

| Uso | Cor | Código |
| --- | --- | --- |
| Marca e ações principais | Azul institucional | `#0A2F5A` |
| Azul de apoio | Azul médio | `#123F70` |
| Destaques | Dourado | `#D2A62B` |
| Fundo da aplicação | Cinza azulado claro | `#F4F7FB` |
| Texto principal | Grafite azulado | `#172133` |
| Sucesso | Verde | `#157F57` |
| Atenção | Âmbar | `#9B6900` |
| Erro | Vermelho | `#BD2C2C` |

Os valores ficam centralizados nas variáveis de `resources/css/app.css`.

## Componentes

- Bordas arredondadas de no máximo `7px`.
- Ícones da biblioteca Lucide.
- Botão azul para a ação principal da tela.
- Dourado usado como destaque, não como cor de grandes áreas.
- Formulários divididos em seções numeradas.
- Campo obrigatório identificado por asterisco vermelho.
- Campo condicional acompanhado de uma frase curta sobre quando é obrigatório.
- Erros exibidos junto ao campo e com resumo no início do formulário.

## Responsividade

- Navegação lateral fixa a partir de `992px`.
- Menu lateral móvel em formato offcanvas abaixo de `992px`.
- Indicadores em quatro colunas no desktop, duas em tablet e uma no celular.
- Formulários passam para uma coluna no celular.

## Acessibilidade e uso

- Todo campo deve possuir `label` associado.
- Botões somente com ícone devem possuir `aria-label` e `title`.
- Cores de status nunca devem ser a única forma de comunicar uma situação.
- Estados de foco devem permanecer visíveis para navegação por teclado.
- Envios devem desabilitar o botão e manter proteção no servidor contra repetição.
