# Implantação — RS Connect v36.20.13.2

## Hotfix CPF/CNPJ no Checkout PagBank

Esta revisão corrige o erro:

```text
Field has an invalid value. Campo recusado: customer.tax_id.
```

A aplicação agora valida os dígitos verificadores do CPF/CNPJ antes de montar o checkout. Quando o documento salvo no cadastro está ausente ou inválido, ele não é enviado ao PagBank. Como `customer_modifiable=true`, o comprador poderá informar o documento correto diretamente na página de pagamento.

Existe ainda uma proteção adicional: se o PagBank recusar especificamente `customer.tax_id`, o sistema repete a criação uma única vez sem esse campo.

## Banco de dados

Não existe migration nova. Continua obrigatória apenas:

```text
database/migrations/087_webhook_security_events.sql
```

## Deploy

1. Faça backup da pasta atual e do banco.
2. Preserve `.env`, uploads, storage e volumes.
3. Substitua a aplicação pela v36.20.13.2.
4. Reinicie/rebuild o serviço PHP para limpar OPcache.
5. Gere novamente o link de uma cobrança aberta.
6. No checkout PagBank, confirme que o comprador consegue completar ou corrigir CPF/CNPJ.

## Testes

```bash
php tests/Feature/ent028-pagbank-taxid-hotfix-smoke.php
php tests/Feature/ent028-pagbank-auth-hotfix-smoke.php
php tests/Feature/ent028-webhook-security-smoke.php
php tests/Support/run-smoke-tests.php
```

## Rollback

Restaure a pasta da v36.20.13.1. O schema do banco permanece compatível.
