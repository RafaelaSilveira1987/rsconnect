# RS Connect — Etapa 5: grade de disponibilidade no WhatsApp

**Data:** 16/10/2026  
**Base de atualização:** `rs-connect-agenda-etapa4-reconciliacao-completo.zip`  
**Tipo:** mudança aditiva nos textos enviados ao WhatsApp; sem migrations.

## Motivação

A Etapa 4 passou a conciliar `calendar_appointments` com `calendar_internal_slot_allocations`. Na Etapa 5, o cliente pode receber uma visão textual dos horários publicados com situação reconhecida, em vez de apenas a lista de opções retornadas pelo motor. **A grade não decide se uma vaga pode ser reservada:** isso continua a cargo da disponibilidade operacional e das operações transacionais existentes.

## Arquivos

- `app/Services/ConversationalSlotGridService.php` — novo projetor textual. Lê `calendar_internal_slots` pela empresa, consulta alocações e conflitos sem vínculo e classifica as linhas com `PublishedSlotStatusService`. Só as opções devolvidas e filtradas pelo `CalendarAvailabilityService` são numeradas.
- `app/Services/CalendarConversationService.php` — chama o projetor na construção de alternativas e, quando não há ofertas no modo publicado, apresenta estados reconhecidos do dia. Se não houver diagnóstico seguro, usa o texto anterior.
- `tests/Feature/calendar-whatsapp-status-grid-20261016.php` — teste independente para capacidade, lotação, bloqueio, conflitos, modalidade, falta de vínculo e truncamento.

## Regras de proteção

1. **Nunca oferecer horário não aprovado pelo motor:** somente opções elegíveis recebem número; os demais são informativos, sem identificador selecionável.
2. **Evitar falsos "ocupados":** um horário sem oferta pode estar bloqueado, lotado, pendente, em conflito, fora da antecedência, fora do período ou da modalidade solicitada. Só usamos “Lotado” quando a projeção comprova o limite consumido.
3. **Respeitar modalidades:** em uma consulta presencial, horários de outras modalidades não integram a grade quando a modalidade é conhecida.
4. **Não expor pacientes:** não incluir IDs, títulos, nomes, contatos ou dados de compromissos; somente hora, modalidade, quantidade de vagas e estado.
5. **Não trocar o processo de reserva:** a escolha é processada pelo caminho já existente de `applySlot()`, com revalidação de capacidade e permissões, inclusive aprovação humana.
6. **Falhar com segurança:** se a tabela publicada, as contagens ou os IDs de vínculo não puderem ser verificados, manter a mensagem antiga em vez de inventar estados.
7. **Evitar mensagens gigantes:** a grade limita a apresentação a 100 linhas e aproximadamente 3.500 bytes de conteúdo, indicando explicitamente quando é parcial. Para consultas maiores, o usuário precisa informar um período e fazer uma nova consulta.
8. **Sem inferência do Google Calendar:** a grade de ocupados vale somente para a agenda *interna publicada*. Modo calculado e Google permanecem com a apresentação de vagas que foi comprovada pelo respectivo motor.

## Exemplo

Se a grade da empresa tiver em 17/10/2026 os horários 08:00, 09:00, 10:00 e 11:00, com um compromisso conciliado em 10:00 e capacidade 4, o texto poderá ser:

```
Confira a disponibilidade consultada:

*Horários de 17/10/2026*
1. 08:00 — 4/4 vagas livres · Presencial
2. 09:00 — 4/4 vagas livres · Presencial
3. 10:00 — 3/4 vagas livres · Presencial
4. 11:00 — 4/4 vagas livres · Presencial
Somente opções numeradas podem ser escolhidas. A vaga será conferida novamente antes da reserva.

Responda com o número de uma opção disponível ou informe outro dia.
```

Exemplo ilustrativo; a exibição operacional depende de antecedência, profissional, modalidade, conflitos e disponibilidade do momento.

## Validação antes de produção

1. Faça backup do banco e dos arquivos.
2. Instale o patch sobre a Etapa 4. Reinicie o serviço PHP ou limpe OPcache pelo processo de implantação habitual.
3. Verifique a sintaxe: `php -l app/Services/ConversationalSlotGridService.php` e `php -l app/Services/CalendarConversationService.php`.
4. Execute o teste: `php tests/Feature/calendar-whatsapp-status-grid-20261016.php`.
5. Em **homologação**, publique 4 horários presenciais, capacidade 4, e concilie um compromisso às 10:00. Solicite pelo WhatsApp os horários para a data. Verifique 10:00 como 3/4, se as regras permitirem sua oferta.
6. Ocupe as 4 vagas e repita. Verifique 10:00 como *Lotado*, sem número selecionável.
7. Bloqueie um horário: a mensagem não poderá chamá-lo de ocupado.
8. Teste modalidade presencial somente em dias autorizados, antecedência mínima, responsável profissional e horário de atendimento.
9. Escolha uma opção numerada. Verifique que o banco revalida a vaga e que a mensagem distingue pré-reserva e confirmação da equipe.
10. Teste consulta sem vagas, períodos sem slots publicados, muitos horários, e a origem Google / modo calculado para garantir compatibilidade.

## Limitações e próximos passos

- A grade é uma apresentação da leitura mais recente, não uma reserva de vaga. Disponibilidade pode mudar até a escolha.
- O modo publicado ainda possui limite de busca por data (até 120 ofertas) e a listagem de slots publicados possui limite de 1.200 registros. Consultas excepcionalmente grandes são apresentadas como parciais; ainda não há paginação interativa `Próxima página` para WhatsApp.
- A Etapa 5 não libera cancelamento/reagendamento efetivos e não altera o Google Calendar.
- Ainda **não foi realizada** homologação em MySQL real, WhatsApp/Evolution ou carga simultânea.

**Próxima prioridade:** Etapa 6, transações e idempotência para cancelar/reagendar com aprovação humana, seguida dos testes concorrentes reais.
