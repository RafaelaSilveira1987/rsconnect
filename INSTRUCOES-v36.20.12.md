# Instruções de implantação — RS Connect v36.20.12

1. Preserve o arquivo `.env` utilizado na VPS.
2. Substitua o projeto pelo conteúdo deste pacote.
3. Não é necessário executar migration nova para a ENT-026.
4. Execute `php tests/Feature/ent026-tests-sanitization-smoke.php`.
5. Execute `php tests/Support/run-smoke-tests.php` para a suíte completa.
6. Faça o rebuild/redeploy normal da aplicação.
7. Valide login, dashboard, conversas, instâncias, planos e integrações já existentes.

A pasta `tests/` não deve ser utilizada como raiz da aplicação nem publicada como document root.
