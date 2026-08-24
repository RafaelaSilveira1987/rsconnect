# RS Connect — Homologação Final v36.5.8

## Estado antes desta rodada

- 1 Empresa e acesso: OK.
- 2 Minha empresa e usuários: OK.
- 3 Isolamento multiempresa: OK.
- 4 WhatsApp/Evolution: OK.
- 5 Contexto de cliente: OK.
- 5B Identificação de novos contatos: OK.
- 6 Assumir atendimento pausa IA: OK.
- 7 Reprocessamento da IA: execução apresentou falha real; v36.5.8 melhora o diagnóstico para identificar etapa, empresa, assistente, contato e erro. Reteste pendente.
- 8 Comercial: OK.
- 9 Agenda: OK na rodada atual; validações adicionais podem continuar depois do freeze.
- 10 Relatórios: OK.
- 11 Cron de cobrança: cron executou. O teste completo cron → regra → n8n → Evolution → WhatsApp → histórico será feito após esta atualização.
- 12 Backup: OK.
- 13 Segurança: OK.
- 14 Mobile: ajustes prévios aplicados; v36.5.8 fixa o hamburger durante rolagem e adiciona Voltar ao topo. Reteste visual pendente.

## Mudanças da v36.5.8

### Administração RS
- Menu dividido por domínio funcional.
- Fluxos e Templates n8n agrupados sob o módulo n8n.
- Nova visão geral do n8n com métricas de fluxos, empresas e execuções recentes.

### Central de operação
- Saúde geral do sistema.
- Busca por ferramenta, rotina ou integração.
- Filtros por estado e categoria.
- Estados Operando, Atenção, Crítico e Sem evidência.
- Evidência usada para cada status.
- Histórico das últimas verificações por ferramenta.
- Novos checks: Google Agenda, fila da IA, relatórios e migrations, além dos checks existentes.
- Diagnóstico detalhado de falhas recentes da IA.

### Navegação
- Hamburger fixo durante toda a rolagem.
- Botão Voltar ao topo em telas do cliente e do Admin RS.

## Retestes prioritários após deploy

1. Central de operação → Verificar sistema agora e conferir os novos estados.
2. Fila da IA → identificar a causa do item 7 pelos novos detalhes.
3. n8n → validar Visão geral, Fluxos e Templates.
4. Cron de cobrança → validar o fluxo completo de entrega da mensagem.
5. Mobile → rolar páginas longas e validar hamburger fixo, Voltar ao topo e responsividade.
