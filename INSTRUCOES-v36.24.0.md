# RS Connect v36.24.0 — inscrição pública e trial Asaas

## O que foi implementado

- botão **Começar 7 dias grátis** na tela de login;
- cadastro público somente do **Plano Inicial com IA RS Connect**;
- checkout recorrente hospedado no Asaas, apenas por cartão;
- primeira cobrança programada para 7 dias após a conclusão;
- confirmação segura por webhook antes de criar a conta;
- criação automática da empresa, administrador, assinatura em `trialing` e funil comercial;
- sincronização de eventos de checkout, assinatura e cobranças;
- tela administrativa em `Financeiro > Inscrição pública`;
- planos superiores direcionados ao WhatsApp comercial.

## Atualização

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Migration nova:

```text
093_public_signup_asaas_trial.sql
```

Resultado esperado:

```text
100 aplicada(s), 0 pendente(s), 0 divergente(s)
```

## Configuração

1. Cadastre um gateway Asaas em **Financeiro > Meios de pagamento**.
2. Informe API Key, token do webhook e escolha Sandbox durante a homologação.
3. No Asaas, cadastre a URL pública do RS Connect:

```text
https://SEU-DOMINIO/webhooks/payments/asaas
```

4. No campo **Token de autenticação** do webhook, use exatamente o mesmo valor salvo no campo **Segredo/token do webhook** do gateway Asaas no RS Connect.
5. Habilite pelo menos estes eventos:

```text
CHECKOUT_CREATED
CHECKOUT_PAID
CHECKOUT_CANCELED
CHECKOUT_EXPIRED
SUBSCRIPTION_CREATED
SUBSCRIPTION_UPDATED
SUBSCRIPTION_INACTIVATED
SUBSCRIPTION_DELETED
PAYMENT_CREATED
PAYMENT_CONFIRMED
PAYMENT_RECEIVED
PAYMENT_OVERDUE
PAYMENT_DELETED
PAYMENT_REFUNDED
```

6. Abra **Financeiro > Inscrição pública**, selecione o gateway e ative o cadastro.
7. Faça o primeiro teste em Sandbox.

## Segurança

- número completo e CVV do cartão não passam pelo RS Connect;
- senha é armazenada somente como hash e removida da sessão de cadastro após o provisionamento;
- callback do navegador não cria a conta sozinho;
- o webhook Asaas é autenticado, idempotente e usado como fonte de confirmação;
- cadastros são limitados por IP e e-mail.
