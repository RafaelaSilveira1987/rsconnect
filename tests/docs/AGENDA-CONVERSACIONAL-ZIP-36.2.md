# Agenda conversacional — ZIP 36.2

## Fluxo funcional

```text
Contato pede horário
→ RS Connect registra a preferência
→ n8n consulta o Google Agenda
→ horário livre?
   → sim: pré-reserva e aguarda aprovação
   → não: envia opções reais
→ contato escolhe
→ RS Connect valida a opção da busca atual
→ n8n pré-reserva o evento
→ profissional recebe notificação
→ profissional aprova, recusa ou remarca
```

## Estados principais

| Estado | Significado |
|---|---|
| `requested` | Consulta criada |
| `sent` | Consulta enviada ao n8n |
| `received` | Horários recebidos |
| `communicating` | Uma execução está preparando a comunicação |
| `options_sent` | Opções enviadas e aguardando escolha |
| `hold_requested` | Pré-reserva solicitada ao n8n |
| `slot_selected` | Horário pré-reservado/escolhido |
| `empty` | Nenhuma opção encontrada |
| `failed` | Falha na consulta |

## Escolha do contato

O processamento ocorre antes da IA para respostas relacionadas às opções pendentes. Isso evita que mensagens como `1` ou `o segundo` sejam interpretadas fora do contexto.

O sistema aceita:

- número da opção;
- ordinal;
- horário único;
- dia e horário correspondentes.

Se o contato informar uma preferência completa diferente, o fluxo normal atualiza o pré-agendamento e consulta a agenda novamente.

## Idempotência

Cada comunicação é vinculada ao `request_id`. Uma trava curta impede dois callbacks simultâneos de enviarem a mesma lista. Caso o processo seja interrompido durante a comunicação, a trava pode ser retomada depois de dois minutos.

## Aprovação

A escolha do cliente define:

```text
status = awaiting_approval
approval_status = pending
availability_status = slot_selected
```

A aprovação do profissional usa o ciclo Google já existente para confirmar o evento. Cancelamento e recusa liberam o evento conforme as configurações da empresa.
