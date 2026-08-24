# Atualização RS Connect 36.6.9

## Objetivo
Corrigir o intervalo mínimo entre respostas automáticas, tornar a reavaliação da fila suficientemente frequente para esse intervalo e manter a tela de Conversas no ponto de atendimento após o envio humano.

## Banco de dados
A 36.6.9 **não possui migration nova**.

A estrutura mínima continua sendo a migration 052:

```sql
SOURCE database/migrations/052_ai_usage_and_after_hours_recovery.sql;
```

Se 050, 051 e 052 já foram aplicadas, não execute SQL adicional para esta atualização.

## 1. Intervalo mínimo entre respostas
O campo **Assistentes de IA → Intervalo mínimo entre respostas (seg.)** volta a ser aplicado também às mensagens novas persistidas.

Exemplo com `cooldown_seconds = 60`:

1. IA envia uma resposta às 10:00:00.
2. Cliente envia nova mensagem às 10:00:20.
3. A mensagem é salva normalmente e registrada como `ai.cooldown`.
4. Nenhuma nova resposta automática pode ser enviada antes de 10:01:00.
5. A fila rápida reavalia a conversa e responde depois que o intervalo já terminou.

Mensagens adicionais recebidas durante a espera permanecem no histórico. Quando a conversa ficar elegível, o contexto contém as mensagens acumuladas e a fila seleciona a demanda mais recente sem gerar uma resposta por mensagem.

O botão manual **Reprocessar pendências agora** continua podendo ignorar somente o cooldown de forma explícita, preservando as demais proteções.

## 2. Fila rápida da IA no n8n
A rotina diária continua existindo como contingência para falhas antigas. Para o intervalo mínimo, use o template atualizado:

**n8n → Templates → Fila rápida da IA**

Ao baixar o template pelo RS Connect, ele já recebe:
- `APP_URL`;
- `AI_REPROCESS_CRON_TOKEN`.

O workflow executa a cada **1 minuto** e chama:

```text
GET /webhooks/ai-reprocess/queue
Header: X-RS-AI-Reprocess-Token
```

Configure no ambiente:

```env
AI_REPROCESS_CRON_TOKEN=um_token_longo_e_aleatorio
AI_REPROCESS_QUEUE_LIMIT=50
```

`AI_REPROCESS_QUEUE_LIMIT` é opcional e limita quantas pendências podem ser processadas em uma passagem da fila rápida.

Depois de alterar variáveis do ambiente, reinicie/reimplante o serviço antes de baixar o template.

## 3. Conversas sem voltar ao topo
O formulário principal de envio em **Conversas** passa a usar envio assíncrono.

Após clicar em **Enviar** ou pressionar Enter:
- a mensagem é enviada;
- o atendimento passa para Humano como antes;
- o campo é limpo;
- a conversa permanece na posição atual;
- o histórico vai para a mensagem mais recente;
- o cursor volta ao campo de digitação.

Se JavaScript estiver indisponível, o fallback tradicional recarrega a página diretamente em `#conversation-composer`, em vez de voltar ao topo.

## 4. Onde ficam os recursos da 36.6.8
### Franquia e contagem de IA
Super Admin:
**Planos e cobrança → Assinaturas → Ver uso e histórico**.

Cliente:
**Assinatura e uso → Uso do plano**.

### Quem custeia a IA
Super Admin:
**IA e credenciais → Custeio**.

Valores:
- Cliente — chave/conta própria;
- RS Connect — consome franquia do plano.

### Recuperação pós-horário
**Central de operação → Fila da IA → Recuperação pós-horário**.

### Alertas 80%, 95% e 100%
Os alertas são gerados quando o consumo cruza cada faixa. O cliente recebe pelo sino da plataforma e o Super Admin recebe o alerta operacional, conforme suas preferências de alertas.

## Após atualizar
1. Confirme `AI_REPROCESS_CRON_TOKEN` no ambiente.
2. Baixe novamente **Fila rápida da IA** em Templates n8n.
3. Importe/substitua o workflow antigo e ative o novo fluxo de 1 minuto.
4. Configure um assistente com intervalo de 60 segundos para homologação.
5. Execute o checklist `docs/HOMOLOGACAO-FINAL-v36.6.9.md`.
