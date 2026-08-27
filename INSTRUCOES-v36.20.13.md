# Implantação — RS Connect v36.20.13

## 1. Ordem obrigatória

1. Faça backup do banco, `.env`, uploads, storage e pasta da versão atual.
2. Execute `database/migrations/087_webhook_security_events.sql`.
3. Atualize os segredos no `.env`.
4. Publique a nova pasta preservando dados persistentes.
5. Reconfigure os webhooks Evolution e o callback n8n.
6. Homologue pagamentos primeiro no Sandbox.

## 2. Migration

```bash
mysql -u USUARIO -p BANCO < database/migrations/087_webhook_security_events.sql
```

Validação:

```sql
SHOW TABLES LIKE 'webhook_security_events';
SHOW INDEX FROM webhook_security_events
WHERE Key_name = 'uq_webhook_security_source_event';
```

## 3. `.env`

```dotenv
APP_ENV=production
SECURITY_WEBHOOK_STRICT=true
SECURITY_WEBHOOK_ALLOW_INSECURE_LOCAL=false
SECURITY_WEBHOOK_MAX_AGE_SECONDS=300
SECURITY_WEBHOOK_PROCESSING_STALE_SECONDS=900

EVOLUTION_WEBHOOK_TOKEN=TOKEN_EXCLUSIVO_EVOLUTION_COM_32_OU_MAIS_CARACTERES
EVOLUTION_WEBHOOK_MAX_AGE_SECONDS=86400

N8N_CALLBACK_TOKEN=TOKEN_EXCLUSIVO_N8N_COM_32_OU_MAIS_CARACTERES
N8N_WEBHOOK_MAX_AGE_SECONDS=300
```

Não reutilize o mesmo segredo na Evolution, no n8n e nos gateways.

## 4. Evolution API

Depois do deploy, abra **Canais WhatsApp** e reaplique as configurações de cada instância.

A URL continuará no formato:

```text
https://SEU_DOMINIO/webhooks/evolution?instance_id=123
```

O token não deve aparecer na URL. A Evolution deve enviar:

```text
X-RS-Connect-Token: VALOR_DE_EVOLUTION_WEBHOOK_TOKEN
```

## 5. n8n

O callback seguro exige os headers:

```text
X-RS-Connect-Token
X-RS-Timestamp
X-RS-Signature
```

A assinatura é:

```text
HMAC-SHA256(
  chave = N8N_CALLBACK_TOKEN,
  mensagem = timestamp + "." + corpo_JSON_bruto
)
```

O template `docs/n8n_templates/template-billing-whatsapp-email.json` foi atualizado com o nó **Assinar callback RS Connect**.

## 6. PagBank / PagSeguro

No Super Admin, abra **Meios de pagamento** e crie um gateway:

- Serviço: `PagBank / PagSeguro`;
- Ambiente: `Ambiente de teste` durante a homologação;
- Token da API: token Sandbox ou produção;
- Método padrão: Pix, boleto, cartão ou Cliente escolhe;
- Segredo separado do webhook: não é necessário para PagBank.

A aplicação envia o Token da API como Bearer ao criar o Checkout e usa o mesmo token para validar o header `x-authenticity-token`.

Endpoint de notificação:

```text
https://SEU_DOMINIO/webhooks/payments/pagbank
```

Fluxo de homologação:

1. Gere uma fatura aberta.
2. Selecione o gateway PagBank.
3. Gere o link de pagamento.
4. Confirme que o link abre o Checkout PagBank.
5. Pague no Sandbox.
6. Confirme a atualização da fatura.
7. Reenvie o mesmo evento e confirme que a resposta contém `duplicate: true`.

## 7. Outros gateways

- Asaas: configure o `authToken` e salve-o no campo de segredo do webhook. O header esperado é `asaas-access-token`.
- Stripe: use o signing secret `whsec_...` do endpoint.
- Mercado Pago: use a assinatura secreta gerada na configuração de Webhooks.
- InfinitePay/externo: use token forte no header `X-RS-Payment-Token` ou Bearer.

## 8. Rollback

Se a homologação falhar:

1. restaure a pasta da v36.20.12;
2. preserve a tabela `webhook_security_events` — ela é aditiva e não interfere na versão anterior;
3. restaure o `.env` anterior somente se necessário;
4. reconfigure os webhooks Evolution para a versão restaurada.
