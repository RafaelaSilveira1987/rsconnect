# RS Connect — ZIP 36.2

## Agenda conversacional com pré-reserva

Esta versão fecha o fluxo da agenda sem retirar a aprovação profissional:

1. O contato informa dia, horário e modalidade.
2. O RS Connect consulta os eventos reais do Google Agenda pelo n8n.
3. Se o horário estiver livre, ele é pré-reservado e fica aguardando aprovação.
4. Se estiver ocupado, o contato recebe alternativas reais numeradas.
5. O contato pode responder com o número, ordinal ou horário, por exemplo: `1`, `o segundo` ou `17h`.
6. O RS Connect pré-reserva a opção escolhida.
7. O profissional recebe uma notificação e decide aprovar, recusar ou remarcar.

A confirmação definitiva continua dependendo da ação humana quando a empresa exige aprovação.

## Instalação

1. Faça backup dos arquivos e do banco.
2. Substitua os arquivos pelo pacote 36.2.
3. Execute:

```text
database/migrations/046_calendar_conversational_slot_selection.sql
```

4. Faça o redeploy e reinicie o serviço PHP/Apache.
5. Abra **Central de operação → Status do sistema** e confirme `ZIP 36.2`.
6. Abra a configuração da empresa e revise as quatro novas mensagens da agenda.

## Configuração necessária

Na empresa, mantenha ativos:

- pré-agendamento;
- aprovação humana;
- assistente pode sugerir horários;
- busca automática de disponibilidade;
- fluxo n8n da Agenda Google;
- modo correto: espaços livres ou eventos VAGO.

No modo VAGO, o fluxo de pré-reserva, confirmação e liberação do evento deve estar ativo no n8n.

## Mensagens configuráveis

Foram adicionadas mensagens para:

- apresentar horários alternativos;
- confirmar a pré-reserva escolhida;
- informar que não existem horários;
- pedir novamente quando a escolha não for identificada.

Variáveis disponíveis:

```text
{{opcoes}}
{{nome}}
{{data}}
{{hora}}
{{inicio}}
{{modalidade}}
{{dia_preferido}}
{{horario_preferido}}
```

## Respostas reconhecidas

Exemplos aceitos:

```text
1
opção 2
o primeiro
o segundo
pode ser 14h
prefiro 17 horas
quinta-feira às 16h
```

Quando o contato informar outro dia e outro horário completos, a mensagem volta ao fluxo normal e uma nova busca é feita.

## Proteções

- Somente opções retornadas pela busca atual podem ser escolhidas.
- A numeração enviada ao WhatsApp é persistida no banco.
- Opções expiram após 24 horas.
- Callbacks antigos são ignorados quando existe uma consulta mais recente.
- A mesma consulta não envia a lista duas vezes.
- Se o horário for ocupado entre a oferta e a escolha, o contato recebe as opções restantes.
- Se não restar nenhuma opção, o sistema solicita outro dia ou período.
- O horário fica pré-reservado, não confirmado, até a aprovação profissional.
- IA, tags, grupos, coleta de demanda, fila e proteção contra duplicidade permanecem inalterados.

## Validação rápida

1. Peça um horário ocupado pelo WhatsApp.
2. Confirme que o contato recebeu apenas opções reais.
3. Responda `1`.
4. Confirme na Agenda que o evento ficou pré-reservado.
5. Confirme no RS Connect o status **Aguardando aprovação**.
6. Aprove pelo painel.
7. Confirme a atualização definitiva no Google e a mensagem ao contato.

Diagnóstico disponível:

```text
database/diagnostics/calendar_conversational_flow_check.sql
```
