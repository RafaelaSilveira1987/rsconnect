# Atualizar do ZIP 08 para o ZIP 09

1. Faça backup do banco e do projeto.
2. Suba os arquivos do ZIP 09 no GitHub.
3. Faça redeploy no EasyPanel.
4. No Adminer, execute:

```text
database/migrations/010_n8n_tenant_flows.sql
```

5. Entre como Super Admin RS.
6. Abra **Fluxos n8n**.
7. Cadastre os webhooks n8n por empresa.
8. Teste cada fluxo pelo botão **Testar**.

## Ajuste de arquitetura

Antes, alguns fluxos dependiam de variáveis globais no `.env`, como:

```env
N8N_WEBHOOK_URL=
N8N_CALENDAR_WEBHOOK_URL=
```

Agora a configuração correta para comercialização é por empresa no banco, com URL criptografada e logs de envio.

## Token no n8n

Se você preencher o token secreto no RS Connect, o fluxo n8n receberá:

```text
Authorization: Bearer SEU_TOKEN
X-RS-Connect-Token: SEU_TOKEN
X-RS-Connect-Tenant-Id: ID_DA_EMPRESA
X-RS-Connect-Event: evento.disparado
```

Use esses campos no n8n para validar e rotear a automação.
