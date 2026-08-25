# Manual do Super Admin RS

## Papel

O Super Admin mantém a operação global, presta suporte, acompanha integrações e protege o isolamento entre empresas.

## Atribuições

- Validar empresas, planos, permissões e limites.
- Acompanhar saúde da Evolution, webhooks, OpenAI, filas e rotinas automáticas.
- Abrir a empresa correta antes de acessar conversas ou dados operacionais.
- Auxiliar na recuperação de instâncias, sem assumir tarefas rotineiras do cliente.
- Verificar logs, auditoria, backups, relatórios agendados e alertas.

## Regras de suporte

1. Nunca alterar dados de outra empresa sem selecionar o tenant correto.
2. Não assumir uma conversa apenas para inspecioná-la.
3. Ao forçar transferência ou liberação, registrar o motivo.
4. Não excluir uma instância com vínculos sem transferência assistida e conferência da auditoria.
5. Preservar `.env`, tokens e chaves administrativas somente no servidor.

## Checklist de incidente

- Identificar empresa e canal.
- Verificar conexão Evolution e webhook.
- Verificar modo da conversa e responsável.
- Verificar fila fora do horário e tentativas de recuperação.
- Verificar franquia, credencial e consumo da IA.
- Registrar causa, ação e resultado na auditoria.
