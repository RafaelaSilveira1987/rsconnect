# RS Connect — Homologação Final v36.5.9

## Estado antes desta rodada

- 1 Empresa e acesso: OK.
- 2 Minha empresa e usuários: OK.
- 3 Isolamento multiempresa: OK.
- 4 WhatsApp/Evolution: OK.
- 5 Contexto de cliente: OK.
- 5B Identificação de novos contatos: OK.
- 6 Assumir atendimento pausa IA: OK.
- 7 Reprocessamento da IA: execução apresentou falha real; v36.5.9 amplia o diagnóstico para identificar também a instância Evolution, estado da conexão, assistente e última falha. Reteste funcional pendente.
- 8 Comercial: OK.
- 9 Agenda: OK na rodada atual; validações adicionais podem continuar depois do freeze.
- 10 Relatórios: OK.
- 11 Cron de cobrança: cron executou. O teste completo cron → regra → n8n → Evolution → WhatsApp → histórico será feito após esta atualização.
- 12 Backup: OK.
- 13 Segurança: OK.
- 14 Mobile: ajustes prévios aplicados; v36.5.9 move o hamburger para fora do cabeçalho e o fixa diretamente ao viewport. Voltar ao topo permanece disponível. Reteste visual pendente.

## Mudanças da v36.5.9

### Navegação global
- Hamburger removido de dentro do topbar e fixado diretamente ao viewport.
- Estado aberto/fechado refletido em aria-expanded e no rótulo do botão.
- Botão Voltar ao topo mantido em cliente e Admin RS.

### Central de operação
- Monitoramento deixa configuração e histórico detalhado de backup na aba Backups.
- Alertas, incidentes, execuções, falhas e históricos longos exibem as 3 entradas mais recentes e botão Ver mais.
- Histórico de saúde por ferramenta limitado às 3 evidências mais recentes.
- Rotas de banco e migrations levam diretamente para Status do sistema dentro da Central.

### Segurança e tokens
- OPENAI_API_KEY deixa de gerar falso alerta quando existem credenciais ativas por empresa/assistente.
- N8N_CALLBACK_TOKEN é tratado como revisão obrigatória quando há fluxos n8n ativos.
- CALENDAR_MAINTENANCE_TOKEN é identificado como recomendado/opcional quando a manutenção automática por cron não é usada.
- BILLING_CRON_TOKEN, AI_REPROCESS_CRON_TOKEN e Evolution passam a receber validação contextual.

### Fila da IA
- Pendências agrupadas por empresa, instância Evolution e assistente.
- Exibe estado da conexão, quantidade de conversas presas, período das pendências e última falha.
- Explica por que uma execução pode reavaliar apenas 1 item: após a primeira falha do mesmo assistente, novas tentativas daquele grupo são interrompidas na execução atual para evitar repetição em massa.

## Retestes prioritários após deploy

1. Central de operação → Verificar sistema agora e conferir os novos estados.
2. Fila da IA → identificar a causa do item 7 pelos novos detalhes.
3. n8n → validar Visão geral, Fluxos e Templates.
4. Cron de cobrança → validar o fluxo completo de entrega da mensagem.
5. Mobile → rolar páginas longas e validar hamburger fixo, Voltar ao topo e responsividade.
