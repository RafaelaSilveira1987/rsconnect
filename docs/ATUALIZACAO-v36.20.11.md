# RS Connect 36.20.11 — Planos por origem da IA e fidelidade

## Objetivo

Apresentar uma matriz comercial clara, com valores diferentes para IA custeada pela RS Connect e IA paga diretamente pelo cliente, mantendo economia proporcional nos planos superiores.

## Valores padrão

- Inicial: R$ 69 com IA própria ou R$ 99 com IA RS Connect;
- Profissional: R$ 129 com IA própria ou R$ 179 com IA RS Connect;
- Empresarial: R$ 259 com IA própria ou R$ 349 com IA RS Connect.

## Contratos

- 3 meses sem desconto;
- 6 meses com 8%;
- 12 meses com 15%.

## Persistência

A migration 086 adiciona preços separados ao plano e registra na assinatura:

- `ai_billing_mode`;
- `commitment_months`;
- `commitment_ends_at`.

## Compatibilidade

- `monthly_price` permanece disponível e recebe o valor base da modalidade IA RS Connect;
- caso a migration ainda não esteja aplicada, a tela mantém fallback para o preço legado;
- o valor negociado continua editável pelo administrador.
