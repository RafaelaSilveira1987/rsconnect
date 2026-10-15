# RS Connect — Grade de disponibilidade por data (15/10/2026)

## Objetivo
Evitar que a Agenda interna responda **sem vagas** quando há horários elegíveis no mesmo dia pedido e permitir a exibição de uma grade textual compacta no WhatsApp, baseada em slots consultados (não em texto gerado por LLM).

## Diagnóstico no código

1. `CalendarAvailabilityService::searchWindow()` começava em `starts_at` quando o contato informava um horário exato; por exemplo, ao perguntar sobre `20/10/2026 08:00`, uma vaga `07:00` nesse mesmo dia não fazia parte da janela de busca.
2. `generateInternalSlots()` e `generatePublishedInternalSlots()` paravam ao atingir `max_suggestions` (padrão: 5) mesmo para consulta de um dia completo.
3. `CalendarConversationService::slotsForRequest()` aplicava um novo limite de 20 vagas, independentemente de quantas a Agenda tivesse encontrado.
4. Exibir muitas opções exigia evitar que a frase `8 horas` fosse interpretada como a opção de número 8.

## Ajustes efetuados

- Consultas à **Agenda interna** com data identificada ampliam a janela para o dia solicitado, respeitando antecedência mínima e sem alterar a preferência exata para reserva automática.
- Consultas diárias internas usam limite de leitura de até 120 slots; buscas gerais continuam obedecendo a `max_suggestions`. O limite de 120 protege o WhatsApp contra mensagens gigantes. Se a grade de um dia ultrapassar esse limite, ainda será necessária paginação explícita em uma evolução posterior; esta entrega **não garante exibição ilimitada**.
- A conversa preserva até 120 slots retornados pelo motor de disponibilidade, exibe horários agrupados por data quando houver mais de cinco e mantém identificadores numéricos para seleção.
- Capacidade restante é informada somente quando presente no snapshot fornecido pela Agenda publicada. A escolha continua revalidada atomicamente pelo serviço de alocação.
- `8 horas` passa a ser analisado como `08:00`, não como opção 8; `opção 8` seleciona a oitava alternativa.
- Se vagas foram devolvidas pela agenda, mas descartadas por regras de modalidade, a resposta explica a restrição em vez de dizer genericamente que não existe disponibilidade.
- O filtro de modalidade é reaplicado ao interpretar uma resposta do cliente e a numeração dos horários exibidos é contínua.

## Limites e segurança

- Não exibir **Ocupado** para um horário ausente da lista. Ausência pode significar janela fechada, antecedência, modalidade, falta de publicação, agenda externa indisponível ou horário efetivamente ocupado.
- A exibição de vagas ocupadas exige uma leitura completa de estados por fonte (`available`, `held`, `booked`, `blocked`, `unknown`), agregação de capacidade e regras de privacidade sem identificação de outros pacientes. Isso **não foi implementado** neste hotfix.
- No Google Calendar, permanece a janela e o contrato próprios do provedor; esta correção da janela diária atua apenas na Agenda interna.
- A disponibilidade é um retrato no momento da consulta; o slot deve sempre ser revalidado no momento da reserva. A grade não equivale a uma reserva.
- O sistema não pode afirmar que uma vaga real está disponível no servidor sem executar consulta ao banco e, para integrações externas, ao respectivo provedor.

## Homologação essencial com MySQL real

1. Crie três horários `07:00`, `08:00`, `09:00` no mesmo dia; ocupe apenas `08:00`. Peça `08:00`. O sistema deve negar **apenas** `08:00` e oferecer `07:00` e `09:00` se forem permitidos.
2. Configure 48h de antecedência e solicite uma vaga amanhã. Verifique se o bloqueio é atribuído à antecedência, não a uma agenda falsamente lotada.
3. Publique uma turma de capacidade 4 com dois participantes e verifique que `2 vagas` continuam aparecendo.
4. Consulte horários com modalidade presencial permitida somente segunda-feira; vagas de outros dias não podem ser oferecidas como presenciais.
5. Confirme que várias opções têm numeração estável e que `8 horas`, `08:00` e `opção 8` não provocam a mesma interpretação indevidamente.
6. Teste Google Calendar/n8n em separado, inclusive callbacks atrasados e erros de sincronização.
7. Teste simultaneidade: enquanto a grade é exibida, outro atendimento ocupa a última vaga; a seleção posterior deve recusar essa vaga e oferecer atualização.
8. Confira no WhatsApp mensagens acima de 20 opções, em Android e iPhone.

## Testes locais

`php -d zend.assertions=1 -d assert.exception=1 tests/Feature/calendar-daily-grid-completeness-20261015.php`

O pacote não depende de migrations adicionais. Faça backup do código e banco, aplique em homologação e confirme os itens acima antes de colocar em produção.
