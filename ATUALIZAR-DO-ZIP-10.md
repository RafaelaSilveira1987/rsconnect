# Atualizar do ZIP 10 para o ZIP 11

## 1. Subir arquivos

Suba todos os arquivos deste pacote no GitHub/repositório do RS Connect.

## 2. Redeploy

No EasyPanel, faça redeploy do serviço `rsconnect`.

## 3. Rodar migration

No Adminer, selecione o banco `rs_connect` e execute:

```text
database/migrations/012_saas_billing_plans.sql
```

## 4. Testar como Super Admin

Acesse:

```text
/ billing
```

ou pelo menu:

```text
Planos e cobrança
```

Valide:

1. Planos aparecem: Starter, Profissional, Business e Custom.
2. Empresas aparecem com assinatura.
3. É possível criar cobrança manual.
4. É possível abrir "Ver uso" de uma empresa.

## 5. Testar como cliente

Entre com usuário cliente e acesse:

```text
Minha assinatura
```

O cliente deve visualizar:

- plano atual;
- status da assinatura;
- limites;
- uso do mês;
- cobranças.

## 6. Próximo passo sugerido

ZIP 12 — Gateway de pagamento e cobrança automática.

Opções:

- Asaas para boleto, Pix e cartão no Brasil;
- Mercado Pago para checkout Pix/cartão;
- Stripe para cartão/assinatura internacional.
