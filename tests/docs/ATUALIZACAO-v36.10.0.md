# Atualização RS Connect v36.10.0

## Objetivo

Disponibilizar o relatório **Equipe e profissionais** usando a base histórica das migrations 067 e 068.

## Pré-requisitos

- RS Connect v36.9.1 aplicado;
- migration `067_operational_history_metrics_compat.sql` executada;
- aplicar depois `068_conversation_service_cycles_compat.sql`;
- `APP_KEY` preservada para manter os UUIDs públicos válidos.

## Instalação

1. Confirme a branch `feature/atendimento-por-profissional`.
2. Extraia o patch na raiz do projeto.
3. No Adminer, importe `database/migrations/068_conversation_service_cycles_compat.sql`.
4. Execute `git status`, `git add .`, commit e push.
5. Faça rebuild/redeploy no EasyPanel.
6. Atualize o navegador com `Ctrl + F5`.

A migration 068 é obrigatória para preservar métricas por ciclo após reaberturas.

## Permissões

- `reports.team.view_own`: mostra somente o usuário autenticado;
- `reports.team.view_all`: mostra a equipe e permite filtrar um profissional;
- Super Admin: seleciona uma empresa por vez.

## Validação

- abra **Relatórios → Equipe e profissionais**;
- filtre um período e compare os usuários;
- confirme que `tenant_uuid` e `user_uuid` aparecem na URL;
- valide um usuário comum para garantir o escopo próprio;
- exporte o CSV;
- execute `database/diagnostics/team_professional_reports_v36.10.0.sql` quando precisar conferir os dados diretamente no banco.

## Rollback

Retorne ao commit da v36.9.1. A tabela histórica da migration 068 pode permanecer; ela não interfere na versão anterior.
