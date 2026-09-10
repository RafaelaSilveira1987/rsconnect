# RS Connect v36.30.3 — Indicadores de atendimento

## Objetivo

Fechar a lacuna dos indicadores operacionais prevista no escopo original da RS Connect, medindo velocidade, SLA, duração do atendimento, espera atual e desempenho por profissional sem criar métricas históricas que o banco não consiga provar.

## Métricas incluídas

- **Primeira resposta humana:** tempo entre a primeira mensagem recebida do cliente no ciclo e a primeira resposta enviada por um usuário humano.
- **SLA da primeira resposta humana:** percentual de primeiras respostas realizadas dentro da meta escolhida no relatório. A meta aceita de 5 a 1440 minutos e inicia em 30 minutos.
- **Tempo médio do ciclo de atendimento:** tempo corrido entre `opened_at` e `closed_at` dos ciclos encerrados.
- **Espera atual:** conversas abertas que já receberam mensagem do cliente no ciclo atual e ainda não possuem primeira resposta humana.
- **Fora do SLA agora:** subconjunto da espera atual que já ultrapassou a meta selecionada.
- **Por profissional:** primeira resposta, SLA, duração média dos ciclos encerrados pelo profissional, fila atual atribuída, transferências, clientes e agenda.
- **Por setor:** quando Fila/Setores estiver habilitada, mostra carga operacional **atual** por setor (abertas, aguardando, fora do SLA, espera média e membros ativos).

## Regra de confiabilidade

Os indicadores executivos de duração e SLA excluem ciclos históricos recuperados pelas fontes `migration_snapshot` e `migration_069_recovery`. O relatório de equipe mantém a opção de auditoria histórica, mas identifica a origem do dado.

A visão de setor é propositalmente atual. A plataforma não mantém ainda um histórico versionado de `department_id` por ciclo, por isso a versão 36.30.3 não atribui retroativamente desempenho histórico a setores.

## Exportações

O CSV de equipe inclui SLA, respostas dentro/fora da meta, duração média, ciclos medidos, espera atual e setores do profissional. A exportação de auditoria de primeiras respostas informa a meta utilizada e se cada resposta ficou dentro do SLA.

## Banco de dados

Não há migration nova nesta versão.

Migration obrigatória corrente: `107_service_department_memberships.sql`.
