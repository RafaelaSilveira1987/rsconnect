# RS Connect — ZIP 29

## Agenda inteligente: correção da busca e separação Admin/Cliente

Esta versão corrige a mistura de horários antigos com a busca atual e simplifica a tela da empresa.

### Alterações principais

- A lista de horários mostra somente a busca mais recente de cada pré-agendamento.
- Horários antigos de fallback não aparecem junto com o retorno atual do Google.
- Labels de horário são recriados no RS Connect para evitar diferença de fuso em fluxos antigos.
- O modo `Eventos VAGO` só aceita opções que possuam `google_event_id`.
- Slots duplicados são removidos no callback.
- Quando nenhum evento é encontrado, a tela informa o motivo provável:
  - nenhum evento retornado pelo Google;
  - título VAGO não encontrado;
  - evento marcado como Ocupado em vez de Disponível;
  - modalidade diferente da solicitada.
- Tela do cliente mostra somente regras operacionais da agenda.
- URLs, token, calendário, timezone, offset, fallback e diagnóstico ficam visíveis apenas para o RS Admin.
- Ao salvar como cliente, os campos técnicos são preservados e não são apagados.

### Aplicação

1. Substitua os arquivos do ZIP 28 pelo conteúdo deste ZIP.
2. Faça o redeploy no EasyPanel.
3. Não há migration nova.
4. Reimporte os dois templates n8n atualizados ou copie apenas os nós de código alterados.
5. No RS Admin, desative temporariamente o fallback interno durante os testes do Google.
6. Faça `Ctrl + F5`.

### Teste do modo VAGO

1. No Google Agenda, crie um evento na data desejada.
2. Use exatamente `VAGO — ONLINE` ou `VAGO — PRESENCIAL`.
3. Em **Mostrar como**, escolha **Disponível**.
4. No RS Connect, selecione o modo **Eventos VAGO**.
5. Faça a busca.
6. Caso não apareça, o diagnóstico mostrará quantos eventos foram lidos e por que foram descartados.
