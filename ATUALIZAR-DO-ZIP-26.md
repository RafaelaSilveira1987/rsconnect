# Atualizar do ZIP 26 para o ZIP 27

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. Execute a migration:

```text
database/migrations/029_smart_calendar_availability_n8n.sql
```

4. Pressione `Ctrl + F5`.
5. Acesse `/agenda-inteligente`.
6. Configure a empresa e o webhook n8n.
7. Baixe o template em `/n8n-templates` e importe no n8n.

Não remova os fluxos anteriores. Este ZIP complementa o pré-agendamento existente.
