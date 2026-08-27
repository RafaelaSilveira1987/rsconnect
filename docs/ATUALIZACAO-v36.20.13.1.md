# Atualização v36.20.13.1 — Hotfix de autenticação PagBank

## Problema

Ao criar o Checkout PagBank, uma URL base contendo o endpoint `/checkouts` ou uma credencial colada com o prefixo `Bearer`/`Authorization:` podia produzir um header ou caminho incompatível e retornar erro da infraestrutura de autenticação.

## Correção

- normalização defensiva do Token da API no cadastro e no momento da chamada;
- normalização da URL base para o host oficial do ambiente;
- bloqueio de mistura entre Sandbox e Produção;
- mensagem de erro operacional sem exibir o hash técnico devolvido pelo gateway;
- orientação atualizada no formulário de meios de pagamento.

## Compatibilidade

Não há alteração de banco, regras comerciais, cobranças existentes ou webhooks. A migration `087_webhook_security_events.sql` permanece a última migration obrigatória.
