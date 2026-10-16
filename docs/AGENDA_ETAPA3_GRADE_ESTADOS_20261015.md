# RS Connect — Agenda Etapa 3: estados reais dos horários publicados

## Objetivo

Harmonizar o painel **Agenda → Disponibilidades** com a ocupação real de slots publicados pela Agenda interna. Não alterar reserva, cancelamento, remarcação, WhatsApp ou a estratégia de busca.

## Alterações

- `app/Services/PublishedSlotStatusService.php`: projeção determinística sem conexão ao banco e sem gravações.
- `app/Controllers/CalendarAvailabilityController.php`: anexa projeção aos slots retornados pelo `InternalCalendarSlotService`; utiliza fuso da empresa; sinaliza lista potencialmente truncada (1.200 registros em 60 dias).
- `app/Views/calendar_availability/index.php`: estados e filtros corretos, cartões e indicadores por capacidade. Os horários publicados são exibidos mesmo quando ocupados, bloqueados ou expirados. **Vaga livre** deixa de contar horários bloqueados/encerrados.
- `tests/Integration/published-slot-status-regression.php`: 13 casos cobrindo capacidade, legacy, status inválido e horários encerrados.
- `tests/Integration/published-slot-screen-regression.php`: 7 verificações da tela.

## Estados e limites

| Estado mostrado | Critério | Pode aparecer como potencial vaga? |
|---|---|---|
| Com vagas | Horário publicado, ativo, futuro, com capacidade livre conforme alocações | Sim — requer validação final |
| Lotado | Capacidade esgotada por reservas confirmadas | Não |
| Pré-reservado | Capacidade esgotada com ocupação temporária | Não |
| Bloqueado | Slot bloqueado pela agenda | Não |
| Expirado/removido | Estado encerrado ou cancelado | Não |
| Horário encerrado | Fim anterior ao horário local atual | Não |
| Verificar situação | Estado inválido ou inconsistência entre registro e alocações | Não |

`allocationTableAvailable() = false`: o modo legado **não permite inferir quatro vagas** só porque o campo `capacity_total` diz 4; no máximo uma vaga é marcada como potencial. O cadastro pode exibir a capacidade configurada, mas a nova liberação deve ser feita sobre a migration de capacidade.

**Importante:** este diagnóstico é somente dos horários explicitamente publicados na Agenda interna. Espaços vazios não são classificados como ocupados. Em agenda `calculated` ou Google Calendar, os cartões publicados não representam toda a disponibilidade. Além disso, conflito com outro profissional, antecedência, modalidade, contato, regras de calendário e confirmação final precisam continuar sendo verificados pelo serviço de disponibilidade. Não foi alterado o envio de mensagens do WhatsApp, para evitar afirmar ocupação de horários que o Google simplesmente não retornou.

## Instalação

1. Backup de banco e da instalação atual.
2. Em homologação, aplicar o patch mantendo a estrutura de pastas.
3. Executar `php -l` nos 3 arquivos PHP e os testes `php tests/Integration/published-slot-status-regression.php` e `php tests/Integration/published-slot-screen-regression.php`.
4. Se houver OPcache, reiniciar serviço PHP.
5. Verificar uma empresa com capacidade 4, um slot parcialmente ocupado, um bloqueado, um lotado e um horário passado. Conferir a contagem e comparar com reservas reais.
6. Repetir um agendamento pelo WhatsApp e confirmar que nada foi alterado em seleção, aprovação e capacidade.

## Testes realizados neste pacote

- 13 cenários de projeção de estado passaram.
- 7 verificações estáticas da tela passaram.
- Passaram os smoke tests de Agenda, capacidade, consulta e mensagens rápidas selecionados.
- Um teste antigo (`calendar-preschedule-confirm-v36413-smoke.php`) falhou nos critérios de versão de manifesto e ausência de migration, já incompatíveis com a base utilizada. Não foi modificado.
- **Não executado:** concorrência real com MySQL, integração Google Calendar e validação visual de navegador real.

## Próxima subetapa

Compatibilizar a **grade textual do WhatsApp** com estados auditáveis, sem expor dados de outros clientes e somente quando as regras de disponibilidade já tiverem filtrado a data/modalidade/antecedência; depois integrar testes concorrentes com MySQL, cancelamento e remarcação.
