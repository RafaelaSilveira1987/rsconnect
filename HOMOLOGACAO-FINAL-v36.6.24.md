# Homologação final — RS Connect 36.6.24

## 1. Migration

- [ ] Aplicar `057_calendar_modality_before_availability.sql`.
- [ ] Painel técnico mostrar migration base `057`.

## 2. Pedido com dia/horário, sem modalidade

Enviar:

> Quero marcar uma reunião amanhã às 10h.

Esperado:

- [ ] intenção de agenda reconhecida;
- [ ] dia/horário preservados;
- [ ] resposta: Online ou Presencial?;
- [ ] nenhuma consulta de disponibilidade no n8n antes da resposta;
- [ ] nenhum evento VAGO alterado.

Depois responder:

> Online

Esperado:

- [ ] `appointment_modality=online`;
- [ ] consulta de disponibilidade criada;
- [ ] payload contém `preference.modality=online`;
- [ ] somente `VAGO - ONLINE` (ou aliases online configurados) entram nas opções.

## 3. Presencial

Repetir com:

> Quero agendar presencial amanhã às 10h.

Esperado:

- [ ] não perguntar modalidade novamente;
- [ ] consultar somente eventos configurados como presenciais.

## 4. Pedido sem dia/horário

Enviar:

> Quero agendar.

Esperado:

- [ ] perguntar modalidade primeiro;
- [ ] após `Presencial`, pedir dia/período/horário;
- [ ] só consultar o Google quando as duas etapas estiverem completas.

## 5. Troca de modalidade

Com uma busca online já feita, responder:

> Prefiro presencial.

Esperado:

- [ ] invalidar opções anteriores;
- [ ] liberar hold anterior, quando existir;
- [ ] criar nova consulta;
- [ ] retornar somente disponibilidade presencial.

## 6. Defesa no backend

- [ ] requisição manual de disponibilidade com modalidade indefinida retorna `modality_required` e não chama n8n;
- [ ] callback com slot de modalidade diferente é descartado;
- [ ] template Eventos VAGO recusa busca sem modalidade.

## 7. Regressões

- [ ] `Tenho uma reunião amanhã às 10h` não abre agenda;
- [ ] fora do horário continua respeitando a política do agente;
- [ ] cooldown continua respeitado;
- [ ] conversa em Humano não recebe automação;
- [ ] Google Calendar continua sem criar compromissos aleatórios.
