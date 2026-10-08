# Atualização RS Connect 36.42.4

## Objetivo

Isolar completamente os fluxos n8n marcados como **Inativo** do processamento automático por empresa e tornar a evidência operacional fiel às chamadas HTTP realmente efetuadas.

## O que muda

- somente registros `n8n_tenant_flows.status = active` podem receber eventos automáticos da empresa;
- quando uma empresa não possui fluxo ativo para o evento, o dispatcher encerra sem chamada HTTP e sem registro `skipped`;
- eventos de uma empresa identificada não caem mais no `N8N_WEBHOOK_URL` global;
- uma URL legada que coincida com um fluxo cadastrado como inativo também é bloqueada para execução automática;
- **Testar fluxo** permanece disponível como ação manual, inclusive para fluxo inativo;
- histórico e métricas do módulo n8n mostram apenas `success` e `error`, isto é, tentativas externas reais.

## Homologação sugerida

1. Deixe os fluxos da empresa como **Inativo**.
2. Gere uma mensagem recebida e altere um compromisso da Agenda.
3. Confirme que não houve nova execução no n8n e que **Execuções recentes** não ganhou registro `skipped`.
4. Ative um fluxo compatível e repita o evento; agora deve existir chamada e registro de sucesso/erro.
5. Volte o fluxo para **Inativo** e use **Testar fluxo**; o teste manual deve continuar funcionando sem mudar o status do cadastro.

## Banco de dados

Nenhuma migration nova. Permanece obrigatória:

`124_calendar_slot_capacity_mode.sql`
