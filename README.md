# RS Connect — v36.20.12

Esta versão executa a **ENT-026 / PA-001**, removendo a aplicação duplicada que existia dentro de `tests/` e organizando a suíte de validação em uma estrutura única e segura.

A matriz comercial, os planos e todas as funcionalidades entregues na v36.20.11 permanecem preservados.

## Saneamento da suíte de testes

- 570 arquivos espelhados foram identificados dentro de `tests/`;
- 465 eram cópias idênticas da aplicação;
- 105 eram cópias divergentes, principalmente do snapshot v36.18.4;
- 83 smoke tests foram preservados em `tests/Feature/`;
- 29 contratos e cenários JSON foram preservados em `tests/Contract/Fixtures/`;
- a restauração lógica da árvore anterior está documentada por hashes SHA-256.

Execute `php tests/Support/run-smoke-tests.php` para rodar a suíte.

## Funcionalidades comerciais preservadas

## Matriz comercial padrão

| Plano | IA própria do cliente | IA RS Connect | Canais | Agentes | Usuários |
|---|---:|---:|---:|---:|---:|
| Inicial | R$ 69/mês | R$ 99/mês | 1 | 1 | 3 |
| Profissional | R$ 129/mês | R$ 179/mês | 2 | 2 | 6 |
| Empresarial | R$ 259/mês | R$ 349/mês | 5 | 5 | 15 |

## Fidelidade

- 3 meses: preço padrão;
- 6 meses: 8% de desconto mensal;
- 12 meses: 15% de desconto mensal.

O ciclo de cobrança continua independente do prazo mínimo. É possível, por exemplo, cobrar mensalmente um contrato com permanência mínima de 6 ou 12 meses.

## O que mudou

- seletor entre **IA RS Connect** e **IA própria do cliente**;
- preços dos cartões recalculados sem recarregar a página;
- cálculo do valor por canal e do total mínimo do contrato;
- franquia de respostas exibida somente na modalidade IA RS Connect;
- edição de dois preços por plano;
- descontos de 6 e 12 meses configuráveis;
- assinatura armazena modalidade de IA, fidelidade e término do compromisso;
- valor mensal sugerido automaticamente ao vincular um plano;
- limites padrão atualizados para 3 usuários no Inicial, 2 agentes no Profissional e 5 agentes no Empresarial.

## Banco de dados

Execute:

```text
database/migrations/086_plan_ai_mode_and_commitment.sql
```

A migration é idempotente e preserva assinaturas existentes. O preço legado `monthly_price` passa a acompanhar o valor com IA RS Connect para manter compatibilidade com rotinas antigas.

Consulte `INSTRUCOES-v36.20.12.md`, `docs/ATUALIZACAO-v36.20.12.md` e `docs/diagnostics/ENT-026-TESTS-SANITIZATION-v36.20.12.md`.
