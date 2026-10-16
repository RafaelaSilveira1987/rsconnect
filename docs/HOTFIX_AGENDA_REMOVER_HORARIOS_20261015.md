# RS Connect — Hotfix de exclusão de horários publicados (15/10/2026)

## Sintoma

Em **Agenda → Disponibilidades → Horários liberados**, ao clicar **Remover** em uma vaga publicada, o RS Connect respondia com **404 / Registro não encontrado**, sem cancelar a oferta.

## Causa

O `TenantIsolationService` consultava o tipo padrão de `slot_id` (`availability_slot`, tabela `calendar_availability_slots`) mesmo no endpoint POST `/calendar/availability/internal-slots/cancel`, cuja vaga real pertence a `calendar_internal_slots`.

## Correção

- `PublicId`: acrescenta tipo `internal_slot`, com mapeamento específico **somente** para o endpoint de cancelamento interno.
- `TenantIsolationService`: identifica `internal_slot` pela tabela `calendar_internal_slots`, mantendo checagem de `tenant_id`.
- `InternalCalendarSlotService::cancelSlot`: usa transação, bloqueio da linha `FOR UPDATE`, revalidação de ocupação e remoção lógica (`status = cancelled`), sem `DELETE` de compromissos ou alocações.
- `CalendarAvailabilityController::cancelInternalSlot`: valida IDs e devolve aviso amigável em vez de propagar exceções.

**Atenção:** Não confundir remoção da disponibilidade publicada com cancelamento de compromisso. Uma vaga com alocação `held` ou `booked` é protegida e exige tratar primeiro o compromisso correspondente.

## Arquivos alterados

1. `app/Core/PublicId.php`
2. `app/Services/TenantIsolationService.php`
3. `app/Services/InternalCalendarSlotService.php`
4. `app/Controllers/CalendarAvailabilityController.php`
5. `tests/Feature/calendar-published-slot-removal-v36460-smoke.php` (novo)

Não há alterações de banco ou migrations.

## Implantação segura

1. Faça backup do banco de dados e dos quatro arquivos PHP em produção.
2. Aplique o **patch somente se** o projeto base já for Agenda Etapa 3 (grade de estados). Para outras versões, use o projeto completo após comparar customizações.
3. Valide a sintaxe PHP:

   ```bash
   php -l app/Core/PublicId.php
   php -l app/Services/TenantIsolationService.php
   php -l app/Services/InternalCalendarSlotService.php
   php -l app/Controllers/CalendarAvailabilityController.php
   ```

4. Opcionalmente execute o smoke test:

   ```bash
   php tests/Feature/calendar-published-slot-removal-v36460-smoke.php
   ```

5. Atualize/reinicie o processo PHP/OPcache se necessário.
6. Em **Agenda → Disponibilidades**, faça o teste em uma empresa de homologação, com uma vaga sem ocupação. Confirme que desapareceu da lista ativa.
7. Confira uma vaga com pré-reserva ou confirmação: ela **não pode ser removida**. Verifique também a proteção entre empresas diferentes.

## Validações executadas localmente

- Smoke test novo: 13 checagens aprovadas.
- `public-uuid-routing-smoke.php`, `tenant-isolation-hardening-smoke.php`, `calendar-internal-published-slots-v36380-smoke.php`, `calendar-capacity-live-availability-v36431-smoke.php`, `calendar-ambiguity-capacity-v36420-smoke.php`, `calendar-published-lookup-v36391-smoke.php`, `calendar-published-owner-reset-v36392-smoke.php`: aprovados.
- Os quatro arquivos PHP alterados passaram em `php -l`.

### Limitação

Não foi realizado teste transacional real em MySQL neste ambiente. A proteção de concorrência foi inspecionada e testada estruturalmente, mas deve ser homologada com duas operações simultâneas no banco real antes de considerar o cenário de corrida totalmente validado.
