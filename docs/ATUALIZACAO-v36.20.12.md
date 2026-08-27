# Atualização RS Connect v36.20.12 — ENT-026

## Objetivo

Remover a segunda cópia divergente da aplicação que estava armazenada dentro de `tests/`, mantendo apenas testes, contratos e utilitários reais.

## Alterações

- remoção do espelho de `app/`, `public/`, `routes/`, `database/`, `docs/`, `bin/`, `scripts`, configurações e arquivos de inicialização existentes em `tests/`;
- descarte da cópia recursiva `tests/tests/`;
- organização dos 83 smoke tests em `tests/Feature/`;
- organização dos 29 payloads/cenários JSON em `tests/Contract/Fixtures/`;
- criação das categorias `Unit`, `Integration`, `Feature`, `Contract` e `Support`;
- correção dos caminhos relativos dos testes após a movimentação;
- criação de executor central em `tests/Support/run-smoke-tests.php`;
- criação de manifesto SHA-256 da árvore anterior em `docs/diagnostics/ENT-026-tests-original-manifest.json`;
- atualização dos comandos de homologação históricos que apontavam para a pasta antiga.

## Banco de dados

Nenhuma migration nova. A migration obrigatória permanece:

```text
database/migrations/086_plan_ai_mode_and_commitment.sql
```

## Execução

```bash
composer test
```

ou:

```bash
php tests/Support/run-smoke-tests.php
```
