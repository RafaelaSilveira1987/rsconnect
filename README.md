# RS Connect 36.29.5

Fluxo de atendimento editável e regras operacionais disponíveis no módulo de Assistentes.

Principais alterações desta versão:

- a ordem do atendimento agora pode ser reorganizada por empresa;
- a sequência configurada passa a influenciar qual informação pendente será solicitada primeiro;
- o cliente pode ajustar regras do dia a dia diretamente em **Assistentes**;
- perguntas obrigatórias, mensagens, políticas de negócio e permissões operacionais ficam disponíveis ao administrador do cliente;
- segmento, modelo-base, versão, provedor/modelo de IA, credenciais, integrações externas e proteções estruturais ficam no RS Admin quando puderem interromper a operação;
- `policy.fail_closed` não pode ser desligado pelo cliente;
- o editor da sequência foi redesenhado em cards responsivos, sem rolagem horizontal;
- regras de segurança e Policy Engine continuam valendo independentemente da ordem visual;
- agrupamento de mensagens e prioridade do turno atual da versão 36.29.4 foram preservados.

Migration obrigatória atual:

`106_agent_message_grouping_context_priority.sql`

Não existe migration nova nesta versão.

Depois do deploy, execute `php bin/migrate.php status` e reinicie o PHP-FPM/container para limpar OPcache.
