# RS Connect 36.31.1 — Relatórios PDF e homologação conversacional

## Objetivo
Adicionar exportação PDF profissional diretamente aos relatórios sem remover os CSVs e manter a homologação da 36.31.0 separada de qualquer expansão financeira.

## Relatórios
- `GET /reports/pdf`: relatório executivo completo ou temático.
- `GET /reports/team/pdf`: PDF de Equipe e profissionais.
- CSVs existentes permanecem disponíveis.
- O PDF usa os mesmos filtros e a mesma fonte de dados exibida na tela.
- Comercial, Cobranças e Equipe passaram a ter saída PDF própria.

## Homologação conversacional
A 36.31.0 continua em homologação com: demanda, paciente/cliente atual, online/Meet, presencial/dias permitidos, valor/pagamento, sem vaga, encaminhamentos especiais e respostas em blocos.

## Banco
Nenhuma migration nova. Última obrigatória: `110_conversation_lifecycle_e2e_consistency.sql`.
