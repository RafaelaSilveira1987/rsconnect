# Atualização RS Connect 36.44.4

## Objetivo

Reduzir a complexidade da configuração de uma empresa sem remover as proteções estruturadas do runtime. A Ativação Assistida passa a traduzir respostas de negócio para triagem, workflow, capabilities, agenda e políticas.

## Alterações

- Tela única **Como seu atendimento deve funcionar**.
- Seleção visual das informações que o assistente deve coletar.
- Horário de atendimento com SLA opcional recolhido.
- Agenda configurada com Sim/Não, duração, capacidade, antecedência e aprovação.
- Limites de desconto, reembolso, exceção, faixa etária e casos especiais em linguagem natural.
- Salvamento transacional de todas as estruturas relacionadas.
- Configuração técnica continua disponível em áreas avançadas, mas não é necessária no onboarding.

## Banco

Não há migration nova. Permanece obrigatória `125_native_financial_lifecycle_notifications.sql`.

## Homologação

```bash
php tests/Feature/assisted-business-configuration-v36444-smoke.php
php tests/Support/run-smoke-tests.php
```
