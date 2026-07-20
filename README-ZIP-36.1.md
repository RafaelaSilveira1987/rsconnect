# RS Connect — ZIP 36.1

## Fila da IA e reprocessamento agendado

### O que foi incluído

- **Central de operação > Fila da IA** para o Super Admin RS.
- Reprocessamento manual seguro de todas as empresas.
- Horário diário, fuso e limite máximo configuráveis no painel.
- Histórico de execuções e empresas com mensagens presas.
- Atalho **Saúde e IA** nos cards de empresas.
- Bloqueios de concorrência para evitar respostas duplicadas.
- Endpoint, comando CLI e template n8n para o agendamento.

### Atualização obrigatória do banco

Execute:

```text
database/migrations/043_ai_reprocess_schedule.sql
```

### Variável obrigatória para cron/n8n

Adicione ao `.env`:

```env
AI_REPROCESS_CRON_TOKEN=gere_um_token_forte
```

Depois reinicie ou faça redeploy da aplicação.

### Configuração pelo painel

1. Entre como Super Admin RS.
2. Abra **Central de operação**.
3. Selecione **Fila da IA**.
4. Ative a rotina, escolha o horário e salve.

### Ativação do agendador

Importe no n8n:

```text
docs/n8n_templates/template-ai-reprocessamento-agendado.json
```

No nó HTTP, informe o domínio do RS Connect e o mesmo token do `.env`.

Mais detalhes em `docs/AI-REPROCESSAMENTO-AGENDADO.md`.
