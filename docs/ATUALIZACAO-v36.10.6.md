# RS Connect v36.10.6 — Auditoria final das métricas de equipe

Esta versão fecha a conferência do relatório por profissional sem nova migration.

## O que mudou

- detalhamento dos ciclos que formam o tempo médio de primeira resposta;
- exibição simultânea do horário local da empresa e preservação do UTC no CSV;
- média geral calculada diretamente sobre os ciclos, evitando média de médias arredondadas;
- menor e maior tempo de resposta no período;
- quantidade de ciclos ativos aguardando resposta humana;
- alerta para respostas anteriores à entrada do cliente;
- exportação detalhada em CSV com UUID público da conversa;
- IDs numéricos internos não são exibidos na tela nem no CSV.

## Banco de dados

Não há migration nova. A migration obrigatória mais recente continua sendo:

`071_utc_datetime_contract_compat.sql`

## Teste recomendado

1. Abra **Relatórios → Equipe e profissionais**.
2. Filtre a data do atendimento homologado.
3. Confira os ciclos de 28s e 16s na seção **Auditoria das primeiras respostas**.
4. Confirme que a média exibida é 22s.
5. Exporte **1ª respostas** e confira os horários local e UTC.
6. Confirme que **Datas inconsistentes** permanece em zero.
