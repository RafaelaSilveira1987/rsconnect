# Atualização RS Connect 36.6.8

## Objetivo
Controlar a franquia de IA pelo que realmente gera custo para a RS Connect e recuperar automaticamente demandas recebidas fora do horário de atendimento.

## Regra comercial da franquia
A partir desta versão o antigo limite **Mensagens/mês** passa a ser **Interações de IA/mês**.

Exemplo: se o Starter tinha `messages_month = 1500`, a migration converte para `ai_interactions_month = 1500`.

Conta 1 interação somente quando uma resposta automática da IA é efetivamente enviada usando:
- a chave global da RS Connect; ou
- uma credencial marcada como **Custeio RS Connect**.

Não consome a franquia:
- mensagem recebida do contato;
- resposta enviada por usuário humano;
- mensagem fixa de ausência/transferência;
- resposta automática usando uma credencial marcada como **Custeio cliente**.

Uso com chave própria continua registrado separadamente para auditoria.
Quando OpenAI/Gemini devolvem métricas de uso, o RS Connect também registra tokens de entrada/saída para análise interna; isso não altera a regra comercial de 1 resposta automática = 1 interação.

## Alertas e bloqueio
- 80%: aviso de consumo.
- 95%: aviso de proximidade do limite.
- 100%: novas respostas automáticas custeadas pela RS Connect ficam pausadas.

Ao atingir 100%:
- o WhatsApp continua recebendo mensagens;
- a conversa continua armazenada;
- atendimento humano continua disponível;
- assistentes usando chave própria do cliente continuam elegíveis;
- mensagens bloqueadas pela franquia permanecem aptas a nova tentativa depois da renovação/aumento do limite.

## Credenciais de IA
Em **IA e credenciais**, cada credencial passa a ter o campo **Custeio**:
- **Cliente — chave/conta própria:** não reduz a franquia RS.
- **RS Connect — incluída no plano:** reduz a franquia quando a resposta automática for enviada.

A ausência de uma credencial cadastrada mantém o fallback por chave global do ambiente, classificado como **RS Connect**.

**Importante na primeira atualização:** credenciais já existentes são classificadas conservadoramente como **Cliente**. Revise essa tela e marque como **RS Connect** somente as chaves que são realmente pagas pela RS.

## Recuperação pós-horário
Quando uma mensagem chega fora do expediente do assistente:
1. a mensagem é salva normalmente;
2. a conversa entra na fila pós-horário;
3. a mensagem fixa de ausência, quando configurada, é enviada no máximo uma vez naquela janela;
4. novas mensagens do mesmo contato atualizam a mesma pendência;
5. o Monitor operacional tenta a retomada no próximo horário válido.

Antes de responder, a recuperação confirma:
- conversa ainda em modo IA;
- ninguém da equipe já respondeu a demanda;
- assistente ativo e resposta automática habilitada;
- horário comercial válido;
- conexão WhatsApp disponível no fluxo normal de envio;
- franquia disponível, quando a credencial é custeada pela RS.

Se a equipe assumir a conversa, a IA não entra. Se a equipe responder, a pendência é encerrada como tratada manualmente.

## Automação necessária
Mantenha ativo o template n8n **Monitor operacional RS Connect**, que chama o endpoint de monitoramento a cada 15 minutos. A partir da 36.6.8 essa mesma verificação também tenta recuperar as conversas pós-horário elegíveis.

Variáveis opcionais:

```env
AI_AFTER_HOURS_MAX_AGE_HOURS=168
OPERATIONS_AFTER_HOURS_RECOVERY_LIMIT=25
```

- `AI_AFTER_HOURS_MAX_AGE_HOURS`: por quantas horas uma pendência antiga ainda pode receber recuperação automática. Padrão: 168h/7 dias.
- `OPERATIONS_AFTER_HOURS_RECOVERY_LIMIT`: máximo de pendências avaliadas em cada execução do monitor. Padrão: 25.

## Banco de dados
Se a versão 36.6.7 já está aplicada:

```sql
SOURCE database/migrations/052_ai_usage_and_after_hours_recovery.sql;
```

Se estiver atualizando diretamente da 36.6.5:

```sql
SOURCE database/migrations/050_human_takeover_customer_context.sql;
SOURCE database/migrations/051_operational_evidence_status.sql;
SOURCE database/migrations/052_ai_usage_and_after_hours_recovery.sql;
```

A migration 052:
- cria `credential_owner` em `ai_provider_credentials`;
- cria `ai_usage_events`;
- cria `ai_usage_threshold_events`;
- cria `ai_after_hours_pending`;
- converte os limites antigos dos planos para `ai_interactions_month` preservando o valor de `messages_month` quando existente.

## Após aplicar
1. Abra **Planos e cobranças → Planos** e confira o limite **Interações IA**.
2. Abra **IA e credenciais** e classifique cada chave como **Custeio RS** ou **Custeio cliente**.
3. Abra a assinatura de uma empresa e confira o bloco **Uso da IA**.
4. Confirme que o workflow n8n **Monitor operacional RS Connect** está ativo.
5. Execute os cenários de `tests/Contract/Fixtures/ai-usage-after-hours-scenarios.json`.
