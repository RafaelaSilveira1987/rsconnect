# Testes da RS Connect

A pasta `tests/` contém somente artefatos de validação. A aplicação executável permanece exclusivamente na raiz do projeto.

## Estrutura

- `Unit/`: testes unitários isolados (reservado para a adoção de PHPUnit/Pest na ENT-032).
- `Integration/`: testes que dependem de banco, Redis ou serviços reais (reservado para a ENT-032).
- `Feature/`: smoke tests comportamentais e de regressão do pacote atual.
- `Contract/Fixtures/`: payloads e cenários JSON usados para validar contratos externos.
- `Support/`: utilitários e executor central dos testes.

## Execução

Executar toda a suíte smoke:

```bash
php tests/Support/run-smoke-tests.php
```

Executar um caso específico:

```bash
php tests/Feature/billing-plan-pricing-v362011-smoke.php
```

Alguns testes históricos verificam documentação de versões antigas ou pendências já registradas. O executor retorna código diferente de zero quando qualquer caso falha, permitindo uso posterior em CI.

## Central de qualidade (21/10/2026)

A nova barreira é `php bin/qa.php --suite=critical`, que executa casos selecionados em subprocessos e reprova se detectar saída de erro interno, mesmo que o PHP encerre com exit 0.

Para relatório amplo e análise dos testes históricos, execute `--suite=all`. Resultados ficam em `storage/qa-reports/` (JSON/JUnit). Consulte `docs/PLANO_TESTES_AUTOMATIZADOS_20261021.md` para os resultados de referência, limitações e integração restrita a homologação.
