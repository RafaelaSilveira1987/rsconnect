# RS Connect — Homologação Final v36.6.0

## Situação atual

- 1 Empresa e acesso: OK.
- 2 Minha empresa e usuários: OK.
- 3 Isolamento multiempresa: OK.
- 4 WhatsApp/Evolution: OK.
- 5 Contexto de cliente: OK.
- 5B Identificação de novos contatos: OK.
- 6 Assumir atendimento pausa IA: OK.
- 7 Fila/Reprocessamento IA: causa identificada em um caso real — instância Evolution desconectada. A v36.6.0 preserva a fila e deixa de classificar/repetir esse cenário como nova falha de IA. Retestar após deploy e novamente após reconectar a instância.
- 8 Comercial: OK.
- 9 Agenda: OK.
- 10 Relatórios: OK.
- 11 Cron de cobrança: cron executou; teste ponta a ponta de entrega ainda pendente.
- 12 Backup: reaberto após erro `Permission denied` no script SSH. v36.6.0 corrige permissão + execução explícita via bash. Retestar backup real e callback.
- 13 Segurança: OK, com revisão contextual de tokens já disponível.
- 14 Mobile: melhorias aplicadas; manter reteste visual final.

## Ajustes desta versão

### Backup
- `scripts/rsconnect-backup.sh` é empacotado como executável.
- Template `Backup automático RS Connect` executa `bash /caminho/rsconnect-backup.sh ...`.
- O callback continua sendo enviado mesmo quando o script falha.
- Após o callback, o workflow sinaliza falha no próprio n8n quando o resultado do backup não for sucesso.

### Incidentes e ocorrências
- Resolver/acompanhar/revisar incidente sincroniza `acknowledged_at` do acompanhamento administrativo.
- Ocorrências antigas passam de `Não revisada` para `Revisada` após a ação.
- Se não existirem outros incidentes abertos, a empresa pode ficar marcada como resolvida; se ainda houver outros problemas, permanece em acompanhamento.

### Fila da IA + Evolution
- Antes de chamar IA/Evolution, o reprocessador valida o estado da instância vinculada.
- Instância desconectada gera estado `blocked`, não um novo erro técnico.
- Mensagens permanecem seguras na fila até a reconexão.
- Central de operação mostra `Aguardando conexão` e quantas pendências estão bloqueadas.

## Retestes prioritários

1. Backup: executar uma rotina real e confirmar arquivo, callback, tamanho, checksum e `verified_at`.
2. Saúde da empresa Mariana: marcar incidente como resolvido e confirmar que a ocorrência muda para Revisada.
3. Fila da IA: com Evolution desconectada, executar reprocessamento e confirmar que não surge novo HTTP 400; após reconectar, executar novamente e confirmar o processamento das mensagens.
4. Cobrança: concluir o teste cron → regra → n8n → Evolution → WhatsApp → histórico.
