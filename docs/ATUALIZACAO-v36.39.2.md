# Atualização RS Connect 36.39.2

Esta versão corrige consultas da Agenda interna publicada que retornavam "sem horários" mesmo quando havia vagas liberadas.

## Correções

- A busca da IA não fica mais limitada ao `owner_user_id` herdado da conversa em pré-agendamentos automáticos.
- Uma nova preferência libera qualquer hold interno anterior e limpa o slot escolhido antes de pesquisar novamente.
- Pré-agendamentos automáticos históricos recebem fallback de descoberta sem filtro de profissional.
- A publicação de horários garante a estratégia `published` por UPSERT.
- Slots já publicados em versões anteriores entram como fallback quando a estratégia antiga ainda estiver em `calculated` e o cálculo não encontrar vagas.
- O diagnóstico da consulta registra janela efetiva, modalidade e owner usado.

Não há migration nova. A migration obrigatória permanece `122_calendar_client_communications.sql`.
