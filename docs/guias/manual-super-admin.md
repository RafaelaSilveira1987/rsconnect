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

## OpenAI 2.0 e governança de consumo

A área **Consumo OpenAI** passa a reunir dois pontos de vista:

- **Oficial:** Usage/Costs da organização OpenAI, quando a Admin API Key estiver configurada.
- **Interno:** telemetria do RS Connect por empresa, assistente, estratégia e conversa.

O Super Admin deve usar a comparação para identificar consumo não atribuído, chamadas fora da plataforma e assistentes com custo anormal. Se houver filtro por projeto na API oficial, a comparação só é válida dentro do mesmo escopo.

Configure orçamento e cotação de referência no servidor quando desejar projeções:

```dotenv
OPENAI_MONTHLY_BUDGET_USD=
OPENAI_USAGE_USD_BRL=
```

## Memória progressiva

A memória é atualizada depois da resposta principal e seu consumo é registrado como evento técnico `summary`, não como uma nova interação comercial de franquia. Em caso de falhas repetidas, consulte `conversation_ai_memory.status/last_error` e desative temporariamente a memória no assistente até corrigir a credencial ou o provedor.

A v36.19.0 mantém `conversation_ai_memory` para o atendimento atual e `contact_ai_memory` para continuidade entre conversas do mesmo contato. Ambas respeitam `tenant_id` e não devem ser compartilhadas entre empresas.

## Governança financeira da IA — v36.19.2

Em **Consumo OpenAI**, filtre uma empresa para configurar seu orçamento de IA custeada pela RS Connect. A política pode somente alertar, forçar temporariamente o modo Econômico ou bloquear novas chamadas com credencial RS no limite. O bloqueio não afeta atendimento humano, regras locais, cache nem credencial própria do cliente.
