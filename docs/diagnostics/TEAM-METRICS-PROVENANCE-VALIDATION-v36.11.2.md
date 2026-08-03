# Validação — métricas históricas e operacionais (v36.11.2)

## Objetivo

Separar os ciclos reconstruídos durante a implantação dos ciclos registrados pela operação atual, sem apagar ou reescrever dados antigos.

## Classificação

- `migration_snapshot` e `migration_069_recovery`: **Histórico recuperado**.
- `message_cycle_recovery` após o corte UTC: **Operacional recuperado**.
- `conversation_created`, `conversation_reopened` e demais fontes após o corte UTC: **Métrica operacional**.
- Qualquer primeira resposta anterior a `rs_datetime_contract.cutover_at_utc`: tratada como histórica para o filtro operacional.

## Teste no relatório

1. Abra **Relatórios → Equipe e profissionais**.
2. Selecione a empresa, o período e o profissional.
3. Sem marcar o filtro, confirme o badge **Histórico recuperado + métricas operacionais**.
4. Confira os contadores de ciclos operacionais e históricos.
5. Marque **Somente métricas operacionais** e atualize.
6. Confirme que primeiras respostas e encerramentos de ciclos históricos saíram dos cálculos.
7. Desmarque o filtro e confirme que voltam para auditoria.

Os dados históricos nunca devem ser excluídos do banco.

## Cenário já conhecido da Rafaela

Considerando o corte de 31/07/2026 e o período de 30/07 a 31/07:

- ciclo 1, `migration_069_recovery`, 28 segundos: histórico recuperado;
- ciclo 2, `conversation_reopened`, 16 segundos: métrica operacional.

Sem o filtro, o relatório mede 2 respostas e média de 22 segundos. Com o filtro operacional, mede 1 resposta e média de 16 segundos.

## CSV

O CSV de primeiras respostas deve conter:

- `qualidade_dado`;
- `qualidade_dado_codigo`;
- `origem_ciclo`;
- `origem_descricao`;
- início confiável em UTC e horário local;
- indicação de filtro operacional.

## Diagnóstico SQL

Execute:

`database/diagnostics/team_metrics_provenance_v36.11.2.sql`

A quarta consulta deve retornar `inconsistencias_classificacao = 0`.
