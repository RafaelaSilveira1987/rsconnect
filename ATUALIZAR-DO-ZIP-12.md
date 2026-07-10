# Atualizar do ZIP 12 para o ZIP 13

## 1. Subir arquivos

Suba todos os arquivos deste pacote no GitHub/repositório do RS Connect.

## 2. Redeploy

No EasyPanel, faça redeploy do serviço `rsconnect`.

## 3. Rodar migration

No Adminer, selecione o banco `rs_connect` e execute:

```text
database/migrations/014_pagbank_billing_reminders.sql
```

## 4. Testar PagBank

1. Entre como Super Admin.
2. Acesse **Gateways de pagamento**.
3. Cadastre um gateway PagBank.
4. Gere link de uma cobrança usando PagBank.
5. Configure o webhook no PagBank:

```text
https://rsconnect.rsautomacaodigital.cloud/webhooks/payments/pagbank
```

## 5. Testar Régua de cobrança

1. Acesse **Régua de cobrança**.
2. Confira as regras criadas automaticamente.
3. Crie ou ajuste uma cobrança com vencimento compatível com alguma regra.
4. Clique em **Processar agora**.
5. Confira o histórico na própria tela.
6. Se houver fluxo n8n da empresa com evento `billing.*`, o n8n deve receber o payload.

## 6. Cron opcional

Para processar a régua automaticamente uma vez por dia, configure uma chamada HTTP para:

```text
https://rsconnect.rsautomacaodigital.cloud/webhooks/billing/reminders/run?token=SEU_TOKEN
```

No `.env`, defina:

```env
BILLING_CRON_TOKEN=SEU_TOKEN
```

Se deixar vazio, o endpoint roda sem token. Em produção, use token.
