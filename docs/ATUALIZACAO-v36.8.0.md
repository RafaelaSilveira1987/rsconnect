# RS Connect v36.8.0 — Agenda opcional por profissional

Esta versão amplia o atendimento por profissional iniciado na v36.7.0. A empresa pode manter a agenda geral atual ou ativar horários individuais para cada usuário.

A atribuição automática continua separada e desligada por padrão.

## Funcionamento

Quando a agenda individual estiver ativa:

- cada usuário pode possuir dias e horários próprios;
- cada profissional pode usar um calendário diferente no Google Agenda;
- a busca de disponibilidade considera somente a agenda escolhida;
- conflitos são bloqueados apenas dentro da agenda do mesmo profissional;
- um agendamento pode ser transferido manualmente para outro profissional;
- profissionais temporariamente indisponíveis podem ter a agenda pausada;
- empresas que não ativarem o recurso continuam usando a agenda geral sem mudança.

## Controles por empresa

Em **Agenda → Disponibilidade → Agenda por profissional**:

```text
[ ] Ativar agenda por profissional
[ ] Exigir profissional nos agendamentos
[ ] Usar automaticamente o responsável da conversa
```

A terceira opção permanece desativada por padrão. Portanto, mesmo que João esteja atendendo a conversa, o agendamento não será atribuído a ele automaticamente, a menos que a empresa escolha essa regra.

## Configuração individual

Cada usuário ativo pode receber:

- agenda ativa ou pausada;
- ID do Google Agenda;
- fuso horário;
- duração padrão do atendimento;
- intervalo entre opções;
- margem ao redor dos compromissos;
- antecedência mínima;
- quantidade de dias pesquisados;
- quantidade máxima de sugestões;
- dias e horários de trabalho.

Quando não existir configuração individual, o usuário começa com os valores gerais da empresa e pode ser personalizado depois.

## Seleção e transferência

O profissional pode ser definido:

- no cadastro manual de um novo compromisso;
- na lista principal da Agenda;
- na fila de pré-agendamentos da Disponibilidade;
- automaticamente a partir do responsável da conversa, somente quando o opt-in estiver ativo.

Ao trocar o profissional, o RS Connect:

- valida se o usuário pertence à empresa e está ativo;
- valida se ele está recebendo agendamentos;
- impede a troca quando houver conflito local no mesmo horário;
- libera pré-reservas da agenda anterior quando necessário;
- remove o vínculo antigo com o Google antes de solicitar nova disponibilidade;
- limpa a disponibilidade anterior para evitar confirmação na agenda errada.

## Agenda interna e Google Agenda

### Agenda interna

Os horários são gerados com base nos dias e expediente do profissional. Compromissos de João não bloqueiam os horários de Pedro, desde que cada um esteja selecionado corretamente.

### Google Agenda

O payload enviado ao n8n passa a informar:

```json
{
  "owner_user_id": 12,
  "professional": {
    "user_id": 12,
    "name": "João",
    "google_calendar_id": "agenda-joao@group.calendar.google.com"
  }
}
```

O `google_calendar_id` individual substitui o calendário geral somente para aquele profissional. Quando estiver vazio, o sistema usa o calendário geral da empresa.

## Migration

Execute pelo **Importar** do Adminer:

```text
database/migrations/065_professional_calendar_profiles_compat.sql
```

A migration:

- é compatível com MySQL/MariaDB sem `ADD COLUMN IF NOT EXISTS`;
- pode ser executada novamente;
- deixa o recurso desativado por padrão;
- cria a tabela `user_calendar_profiles`;
- não altera os agendamentos existentes.

Mensagem esperada:

```text
Migration 065 aplicada: agenda por profissional opcional; seleção automática continua desativada por padrão.
```

## Homologação recomendada

1. Ative a agenda por profissional e mantenha a automação da conversa desligada.
2. Configure João de segunda a sexta, das 08:00 às 12:00.
3. Configure Pedro de segunda a sexta, das 13:00 às 18:00.
4. Crie um compromisso para João às 09:00.
5. Confirme que Pedro continua disponível às 09:00.
6. Tente criar outro compromisso para João no mesmo horário e confirme o bloqueio.
7. Pause a agenda de João e confirme que ele não recebe novos agendamentos.
8. Transfira um compromisso para Pedro e faça uma nova busca de disponibilidade.
9. Confirme que um pré-agendamento vindo da conversa permanece sem profissional quando o opt-in automático estiver desligado.
10. Ative temporariamente o opt-in e confirme que um novo pré-agendamento pode reutilizar o responsável atual da conversa.

## Branch

Esta versão deve permanecer na branch de homologação:

```text
feature/atendimento-por-profissional
```

A release estável `v36.6.39` permanece preservada.
