# Atualizar do ZIP 11 para o ZIP 12

## 1. Subir arquivos

Suba todos os arquivos deste pacote no GitHub/repositório do RS Connect.

## 2. Redeploy

No EasyPanel, faça redeploy do serviço `rsconnect`.

## 3. Rodar migration

No Adminer, selecione o banco `rs_connect` e execute:

```text
database/migrations/013_payment_gateways.sql
```

Se você ainda não aplicou o hotfix 11.1, este ZIP já inclui a migration 012 corrigida, mas não precisa rodar a 012 novamente se a tela de planos já está funcionando.

## 4. Cadastrar gateway

Entre como Super Admin RS e acesse:

```text
Gateways de pagamento
```

Cadastre, por exemplo:

```text
Nome: Asaas RS Produção
Provedor: Asaas
Ambiente: Produção
Método padrão: Pix ou Cliente escolhe
API Key: chave da conta Asaas
Gateway padrão: Sim
```

## 5. Configurar webhook no provedor

Use uma das URLs:

```text
https://rsconnect.rsautomacaodigital.cloud/webhooks/payments/asaas
https://rsconnect.rsautomacaodigital.cloud/webhooks/payments/mercadopago
https://rsconnect.rsautomacaodigital.cloud/webhooks/payments/stripe
```

## 6. Gerar link de pagamento

1. Acesse **Planos e cobrança**.
2. Crie uma cobrança, caso ainda não exista.
3. Acesse **Gateways de pagamento**.
4. Na tabela de cobranças, clique em **Gerar link**.
5. Abra o link gerado para conferir o checkout.

## 7. Testar como cliente

Entre com um usuário cliente e acesse:

```text
Minha assinatura
```

A cobrança deve mostrar o botão:

```text
Pagar agora
```

## 8. Próximo passo sugerido

ZIP 13 — Régua de cobrança e notificações automáticas.
