# RS Connect 36.29.2

Correção do seletor do Laboratório de Assistentes.

## O que mudou

- Empresa e assistente passam a usar formulários independentes. Ao trocar a empresa, um `agent_id` da empresa anterior não é mais reenviado.
- O controller valida se o `tenant_id` existe e se o `agent_id` realmente pertence à empresa selecionada.
- A lista do assistente exibe ID, modelo e status para facilitar a homologação.
- A tela informa quantos assistentes foram encontrados na empresa e mostra explicitamente qual assistente está sendo testado.
- O laboratório exibe sua versão (`36.29.2`) para confirmar se o deploy realmente substituiu os arquivos.
- O CLI ganhou `php bin/test-agent.php --version` para a mesma conferência.

## Sem migration nova

A migration mais recente continua sendo `105_agent_testing_lab.sql`.

## Conferência após deploy

```bash
php bin/test-agent.php --version
php bin/test-agent.php --list-tenants
php bin/test-agent.php --tenant=3 --list-agents
```

O primeiro comando deve retornar `RS Connect Agent Lab 36.29.2`.
