# RS Connect 36.29.3

Correção do Laboratório de Assistentes para troca confiável de empresa e assistente.

- seletores independentes para evitar reaproveitamento de `agent_id` de outra empresa;
- validação server-side do tenant e do assistente;
- ID/modelo/status visíveis no seletor;
- quantidade de assistentes encontrada por empresa;
- versão do laboratório visível na tela e no CLI;
- nenhuma migration nova: permanece `105_agent_testing_lab.sql`.

Após o deploy, valide:

```bash
php bin/test-agent.php --version
php bin/test-agent.php --list-tenants
php bin/test-agent.php --tenant=ID --list-agents
```
