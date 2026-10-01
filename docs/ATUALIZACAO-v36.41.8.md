# RS Connect 36.41.8 — Link de atendimento sempre editável

O detalhe do compromisso passa a exibir o editor **Link de atendimento online** para todo usuário com permissão de gerenciamento da Agenda, independentemente de a modalidade já ter sido classificada como Online. Isso cobre compromissos migrados ou confirmados cuja modalidade ainda esteja como **A definir**.

O endpoint existente `/calendar/meeting-link` continua responsável por validar e persistir o endereço. Quando o compromisso está confirmado, a alteração também tenta sincronizar o Google Agenda.

Não há migration nova. Permanece `123_published_slots_min_notice_policy.sql`.
