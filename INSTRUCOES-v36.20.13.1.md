# Implantação — RS Connect v36.20.13.1

## Hotfix PagBank / PagSeguro

Esta revisão corrige o erro de autenticação semelhante a:

```text
Invalid key=value pair (missing equal-sign) in Authorization header
```

A aplicação agora:

- remove automaticamente `Authorization:` e `Bearer` quando foram colados junto ao token;
- remove `/checkouts` ou outros caminhos da URL base, mantendo apenas o host oficial;
- valida se a URL pertence ao ambiente Sandbox ou Produção selecionado;
- apresenta uma mensagem clara quando a autenticação for recusada.

## Configuração recomendada

No Super Admin, abra **Meios de pagamento** e edite o PagBank:

1. Selecione o ambiente correto.
2. Deixe **URL base da API** vazia.
3. No campo **Token da API**, cole somente o token.
4. Não escreva `Authorization:` nem `Bearer`.
5. Salve e gere novamente o link da cobrança.

URLs usadas automaticamente:

```text
Sandbox:  https://sandbox.api.pagseguro.com
Produção: https://api.pagseguro.com
```

O endpoint `/checkouts` é acrescentado pelo sistema.

## Banco de dados

Este hotfix não cria migration nova. A migration da ENT-028 continua obrigatória:

```text
database/migrations/087_webhook_security_events.sql
```

## Testes

```bash
php tests/Feature/ent028-pagbank-auth-hotfix-smoke.php
php tests/Feature/ent028-webhook-security-smoke.php
php tests/Support/run-smoke-tests.php
```

## Deploy

1. Faça backup da pasta atual e do banco.
2. Preserve o `.env`, uploads, storage e volumes.
3. Substitua a aplicação pela v36.20.13.1.
4. Reinicie/rebuild o serviço PHP para limpar OPcache.
5. Edite e salve novamente o gateway PagBank.
6. Gere um novo link em uma cobrança aberta.

## Rollback

Restaure a pasta da v36.20.13. A estrutura do banco é compatível porque este hotfix não altera schema.
