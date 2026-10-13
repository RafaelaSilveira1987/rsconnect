# Atualização RS Connect 36.43.1

## Objetivo

Corrigir a exibição e a reutilização de horários configurados como **Atendimento por capacidade / turma**.

Na configuração já existente desde a 36.42.0, um horário pode ter capacidade maior que 1 (por exemplo 4 pessoas às 10:00). A 36.43.1 mantém esse mesmo modelo e corrige dois pontos:

1. a busca de disponibilidade volta a oferecer o mesmo horário enquanto ainda houver vagas;
2. o painel mostra a ocupação atual (`1/4`, `2/4`, `3/4`) e quantas vagas restam.

## Comportamento esperado

Para um horário de 10:00–10:50 com capacidade 4:

- sem participantes: `0/4 ocupadas · 4 livres`;
- após a primeira pré-reserva/confirmação: `1/4 ocupadas · 3 livres`;
- após a segunda: `2/4 ocupadas · 2 livres`;
- após a terceira: `3/4 ocupadas · 1 livre`;
- ao atingir `4/4`, o horário deixa de ser retornado em novas buscas.

Cada paciente continua sendo um `calendar_appointment` independente. A capacidade é controlada por `calendar_internal_slot_allocations`, evitando duplicar quatro horários iguais na agenda.

## Homologação

1. Em **Agenda > Configurações**, confirme **Atendimento por capacidade / turma** e capacidade padrão 4.
2. Publique um horário, por exemplo 10:00–10:50.
3. Faça um primeiro pré-agendamento e escolha 10:00.
4. Volte ao painel: a opção deve mostrar `1/4 ocupadas · 3 livres`.
5. Em outro pré-agendamento, clique **Buscar disponibilidade**. O horário 10:00 deve aparecer novamente.
6. Repita até `4/4`. Somente então o horário deve deixar de ser ofertado.
7. Cancele/remarque um dos participantes e confirme que a vaga volta a aparecer.

## Banco

Nenhuma migration nova.

A instalação deve continuar com:
- `124_calendar_slot_capacity_mode.sql` no histórico;
- `125_native_financial_lifecycle_notifications.sql` como migration obrigatória atual do pacote.

## Deploy

Pode ser aplicado como hotfix sobre a 36.43.0. Reinicie/redeploy o container após substituir os arquivos.
