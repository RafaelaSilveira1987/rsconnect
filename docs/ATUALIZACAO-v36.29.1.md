# RS Connect 36.29.1

Hotfix do Laboratório de Assistentes.

## Correções

- `bin/test-agent.php` não usa mais IDs ilustrativos sem explicar que são exemplos.
- `--list-tenants` lista os IDs e slugs reais das empresas.
- `--tenant=ID --list-agents` lista os assistentes válidos daquela empresa.
- `--list-scenarios` lista os cenários existentes.
- empresa pode ser informada por ID ou slug; assistente por ID ou nome exato.
- se a empresa possuir somente um assistente, ele é selecionado automaticamente.
- `--prepare-psychology` valida empresa e assistente antes do INSERT, evitando erro bruto de foreign key.
- mensagens de erro mostram os registros válidos para correção imediata.

## Migração

Não há migration nova. A migration necessária permanece `105_agent_testing_lab.sql`.
