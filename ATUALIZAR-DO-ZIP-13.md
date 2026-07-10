# Atualizar do ZIP 13 para o ZIP 14

1. Envie os arquivos do ZIP 14 para o repositório.
2. Faça redeploy no EasyPanel.
3. Execute a migration:

```text
database/migrations/015_notifications_frontend_billing_templates.sql
```

4. Acesse **Templates n8n** e baixe/importar os novos fluxos:

```text
Cron da régua de cobrança
Disparo de cobrança por mensagem
```

5. Cadastre o fluxo de disparo em **Fluxos n8n** com o evento:

```text
billing.*
```

6. Acesse como cliente e confira:

```text
Dashboard
Notificações
Minha assinatura
```
