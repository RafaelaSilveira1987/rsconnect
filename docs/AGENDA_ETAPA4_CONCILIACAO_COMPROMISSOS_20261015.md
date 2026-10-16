# RS Connect — Agenda, Etapa 4: conciliação de compromissos com horários publicados

## Contexto

Alguns compromissos foram registrados em `calendar_appointments`, mas não possuem linha correspondente em `calendar_internal_slot_allocations`. Por isso, a grade publicada indica `0/4 ocupadas` apesar de haver compromisso confirmado. O hotfix anterior detecta esse conflito e **bloqueia a oferta**. A Etapa 4 permite a vinculação mediante **conferência explícita da equipe**.

## Uso

1. Na empresa correta, abra **Agenda → Disponibilidades** e filtre pelo dia e pelo profissional.
2. Um horário com compromisso ativo sem vínculo mostra **Conflito com agendamento**.
3. Quando o serviço consegue comprovar coincidência **exata** de início/fim, modalidade, profissional, reserva em único horário e capacidade disponível, o bloco apresenta os IDs e títulos dos compromissos e o botão **Conferir e vincular**.
4. Confira os compromissos e confirme. A requisição passa por autenticação, permissão `calendar.manage`, CSRF, isolamento por tenant, travas de registros e transação. Uma alteração concorrente provoca rollback.
5. Atualize a grade. Uma turma de quatro pessoas com um vínculo correto deve mostrar **1/4 ocupada, 3 vagas**, desde que a capacidade realmente permita ofertá-las e não haja outros conflitos.

**Não é uma migração automática em massa.** A equipe escolhe explicitamente cada vínculo. Reservas, confirmação do compromisso, contato, mensagens e eventos Google existentes não são modificados.

## Restrições intencionais

- Não vincula compromissos de modalidade, duração ou profissional diferentes.
- Não adivinha um profissional quando o vínculo está vazio; exibe necessidade de conferência manual.
- Não vincula compromissos já vinculados a outra vaga, ou horários com pré-reserva ativa.
- Não excede `capacity_total` e não altera vaga bloqueada, removida ou expirada.
- Não tenta adivinhar a partir de horários apenas sobrepostos. Uma grade de 30 em 30 minutos com atendimentos de 50 minutos tem slots vizinhos naturalmente sobrepostos; a vinculação requer igualdade exata.
- Os slots vizinhos que se sobrepõem ao compromisso continuam bloqueados pelo avaliador de conflitos até a situação se tornar comprovadamente ofertável.
- No máximo 30 prévias de conflito são carregadas por consulta, para proteger o desempenho do painel. Utilize os filtros de data.
- Não vincula automaticamente ao carregar o painel nem ao receber eventos WhatsApp.

## Mudanças

- `app/Services/PublishedSlotReconciliationPolicy.php`: validação determinística isolada.
- `app/Services/PublishedSlotReconciliationService.php`: prévia, transação e persistência das alocações.
- `app/Controllers/CalendarAvailabilityController.php`: opção para equipe + POST.
- `routes/web.php` + `app/Core/PublicId.php`: rota e reconhecimento de UUID do horário interno.
- `app/Views/calendar_availability/index.php`: ação contextual de conciliação.
- Testes: `tests/Unit/PublishedSlotReconciliationPolicyTest.php` e `tests/Feature/calendar-published-slot-reconciliation-20261015-smoke.php`.

## Implantação

1. Faça backup dos arquivos e do banco MySQL.
2. Aplique o patch por cima da última versão **hotfix-vinculo-compromisso-disponibilidade** (não aplique em versões antigas isoladas).
3. Certifique-se de que as migrations anteriores, principalmente a **124**, estão aplicadas. Esta etapa não cria nem altera tabelas.
4. Valide a sintaxe `php -l app/Services/PublishedSlotReconciliationService.php`.
5. Em homologação, use dois agendamentos: um exatamente igual a vaga e outro com modalidade/horário divergente. Apenas o primeiro deve permitir conciliação.
6. Simule uma turma de 4 com 1 confirmado, confira `1/4` e depois tente consumir uma quinta vaga (deve ser bloqueada).
7. Teste concorrência com dois processos reais de MySQL/InnoDB antes de habilitar em produção.

## Testes executados neste ambiente

- 12 cenários unitários da política de reconciliação: aprovados.
- 9 verificações estruturais do controlador, rotas, proteção de UUID, transação e UI: aprovadas.
- Regressões selecionadas para capacity, slots publicados, conflitos e segurança de reservas: aprovadas.
- PHP lint: aprovado.
- **Não executado:** transações simultâneas contra o MySQL real, teste ponta a ponta do botão pelo navegador e integração real com Google Calendar/WhatsApp. Não se deve considerar esses cenários homologados.
