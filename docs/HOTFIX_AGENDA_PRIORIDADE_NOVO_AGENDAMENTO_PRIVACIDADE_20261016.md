# RS Connect — Correção da lista de compromissos exibida durante nova triagem

Data: 16/10/2026

## Evidência

A resposta WhatsApp `sim, minha primeira consulta` era interpretada pelo serviço `ExistingAppointmentConversationService` como pedido de detalhes sobre um compromisso antigo. A consulta anterior à triagem exibia uma lista com **dois compromissos** em vez de encaminhar o cliente para **nova consulta de disponibilidade**. O nome ao lado do compromisso provinha de `owner_name` (responsável/profissional cadastrado), mas esse identificador foi removido da listagem de seleção por privacidade e clareza.

## Mudanças

- `app/Services/ExistingAppointmentConversationService.php`: respostas à pergunta de primeira consulta deixam de disparar consultas a compromissos antigos, inclusive se houver desambiguação pendente. A pergunta continua sendo processada na triagem normalmente.
- Mantém busca de compromissos existentes quando há pedido explícito de status, confirmação, cancelamento ou remarcação.
- Busca de compromisso de contato vinculada ao contato autenticado na conversa; para registros legados sem `contact_id`, permite somente a conversa vinculada ao mesmo contato.
- A seleção de compromissos existentes exibe data, hora e modalidade, sem nomes de contatos nem do profissional.
- O motor `ConversationalSlotGridService` continua responsável pela lista de disponibilidade, sem ser substituído pela seleção de agendamentos antigos.

## Limitações importantes

A lista de quatro horários publicada no painel não garante que as quatro vagas serão exibidas no WhatsApp: antecedência mínima, modalidade, expediente de agendamento, limites de capacidade, conflitos e condição de aprovação ainda são verificados pelo motor operacional. Esta atualização **não** desabilita essas regras, não faz novas reservas e não altera a integração Google Calendar.

## Homologação

1. Faça backup do serviço PHP e banco e instale o patch em homologação.
2. Inicie uma conversa de teste nova: `Quero agendar uma consulta para 17/10`.
3. Responda à triagem: `sim, minha primeira consulta`.
4. Confirme que o agente prossegue com a próxima pergunta de triagem (se houver) e consulta a grade depois das informações obrigatórias, **sem** oferecer os dois compromissos antigos.
5. Selecione data 17/10/2026 e consulte os horários. Com os quatro horários elegíveis, deve mostrar 08:00, 09:00, 10:00 (3/4 livres), 11:00. Se forem menos, inspecione os motivos de bloqueio do motor de disponibilidade.
6. Teste separadamente `Qual o horário da minha consulta confirmada?`, `Quero cancelar minha consulta` e `Quero reagendar meu atendimento`. Confirme que somente os compromissos associados ao contato aparecem, sem nomes.
7. Não ative confirmação automática nem altere aprovações humanas; este hotfix não modifica essas operações.

## Testes locais

`php tests/Feature/appointment-new-booking-privacy-20261016.php`

Executar também a suíte de regressão da Agenda. Testes automatizados locais não substituem integração com MySQL e WhatsApp reais.
