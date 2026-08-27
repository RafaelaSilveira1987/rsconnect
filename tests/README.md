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
