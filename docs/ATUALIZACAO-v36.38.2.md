# Atualização RS Connect 36.38.2

## Objetivo

Simplificar a navegação da Agenda sem alterar o motor de disponibilidade.

A Agenda passa a usar uma única barra com cinco áreas proporcionais:

- Compromissos
- Visão geral
- Disponibilidades
- Pré-agendamentos
- Configurações

A barra antiga de duas opções e a segunda barra interna deixam de coexistir. Em telas menores, a mesma navegação usa rolagem horizontal.

## Banco de dados

Não existe migration nova. Permanece obrigatória:

`121_internal_calendar_published_slots.sql`

## Validação sugerida

1. Abrir Agenda → Compromissos e confirmar uma única barra de navegação.
2. Acessar cada uma das cinco áreas e confirmar o destaque da aba atual.
3. Validar em resolução desktop e mobile.
4. Confirmar que publicação de horários e consulta da Agenda interna continuam iguais à 36.38.1.
