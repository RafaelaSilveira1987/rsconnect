# Validação técnica — ENT-029 / PA-003

## Resultado

- 313 arquivos PHP validados com `php -l`: 0 erro;
- 3 arquivos JavaScript validados com `node --check`: 0 erro;
- 53 arquivos JSON carregados: 0 erro;
- liveness via roteador: `{"status":"ok"}`;
- readiness sem dependências no ambiente local: HTTP 503 e `{"status":"unavailable"}`;
- teste específico ENT-029: 11 verificações aprovadas;
- suíte completa: 81 aprovados, 9 falhas históricas, 90 total.

## Falhas históricas preservadas

- after-hours-human-queue-v36184-smoke.php;
- ai-efficiency-phase2-v36180-smoke.php;
- evolution-client-admin-v36161-smoke.php;
- evolution-instance-management-v36160-smoke.php;
- instance-create-layout-v36171-smoke.php;
- instance-delete-without-replacement-v36209-smoke.php;
- instance-local-deletion-v36207-smoke.php;
- instance-remote-missing-deletion-v36206-smoke.php;
- openai-usage-menu-v36163-smoke.php.

Nenhuma nova falha foi introduzida pela ENT-029.

## Limitações do ambiente

O readiness não pôde retornar `ok` localmente porque o ambiente de validação não possui o banco MySQL e a APP_KEY reais da VPS. O comportamento de indisponibilidade foi validado: código HTTP 503 e corpo público sem detalhes técnicos.
