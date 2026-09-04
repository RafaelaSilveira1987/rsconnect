## RS Connect 36.27.16

Esta versão parte integralmente da **36.27.15** e mantém:

- cards compactos e responsivos na tela de Assistentes Virtuais;
- roteamento do agente especialista de agendamento;
- migration `101_agent_scheduling_specialist_routing.sql`;
- autoria correta do agente de agenda.

A 36.27.16 acrescenta a correção crítica do ciclo de agenda: uma resposta afirmativa do cliente (`sim`, `pode`, `pode confirmar`) só gera mensagem de confirmação depois que o compromisso é realmente convertido em `confirmed` na Agenda do RS Connect.

Também mantém aprovação humana como regra superior, revalida conflitos/Google Agenda e impede que o modelo declare um horário confirmado apenas por texto livre.

Não há migration nova. Após o deploy, execute `php bin/migrate.php up` para garantir que a migration 101 já esteja aplicada e siga `docs/VALIDACAO-v36.27.16.md`.
