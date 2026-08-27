# RS Connect — v36.20.10

Esta versão corrige o ciclo de mensagens recebidas fora do horário de atendimento.

## O que foi corrigido

- o aviso de ausência passa a ser enviado tanto em conversas sob responsabilidade da IA quanto em modo humano;
- a mensagem informativa é operacional e não consome tokens de IA;
- várias mensagens recebidas no mesmo dia não geram avisos duplicados;
- quando o agente não possui uma mensagem própria, o sistema usa a configuração da empresa e, por último, uma mensagem padrão segura;
- a pendência é programada para a próxima abertura exata do agente, em vez de aguardar blocos de 15 minutos;
- ao iniciar o expediente, a **Fila rápida da IA** pode retomar a conversa na primeira execução do minuto;
- conversas em modo humano continuam apenas sinalizadas na fila e não são respondidas automaticamente.

## Automação necessária

Para a retomada automática funcionar mesmo sem ninguém com o painel aberto, mantenha ativo no n8n o template:

```text
docs/n8n_templates/template-fila-rapida-ia.json
```

Ele chama a cada minuto:

```text
POST /webhooks/ai-reprocess/queue
Header: X-RS-AI-Reprocess-Token
```

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.10.md`.
