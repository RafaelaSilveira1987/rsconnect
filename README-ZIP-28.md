# RS Connect — ZIP 28

## Google Agenda: espaços livres + eventos VAGO

Este pacote parte do ZIP 27 completo e já contém os arquivos PHP, rotas, telas, migration e templates n8n integrados. Não é necessário mesclar trechos manualmente.

## O que foi incluído

- Dois modos de disponibilidade configuráveis por empresa:
  - **Calcular espaços livres**: lê os compromissos reais do Google Agenda e calcula as lacunas conforme expediente, duração, início entre opções, buffer, dias de atendimento e antecedência mínima.
  - **Usar eventos VAGO**: busca eventos com títulos exatos, por padrão `VAGO — ONLINE` e `VAGO — PRESENCIAL`.
- URLs n8n independentes para cada modo.
- Identificação de eventos `opaque` e `transparent`.
- Seleção de calendário Google por `calendar_id`.
- Fuso da empresa e offset UTC configuráveis.
- Pré-reserva do evento VAGO ao clicar em **Usar este horário**.
- Confirmação automática do mesmo evento ao aprovar o pré-agendamento.
- Restauração para VAGO ao rejeitar, cancelar, remarcar ou liberar o horário.
- Releitura do evento antes da atualização e uso de `etag`/`If-Match` para reduzir conflito de reservas simultâneas.
- Registro de `google_event_id`, modalidade, estado e histórico de sincronização.
- Dois templates n8n para consulta real ao Google Agenda.
- O template simulado do ZIP 27 foi mantido apenas para teste de comunicação e fallback.

## Arquivos principais

```text
database/migrations/030_google_calendar_availability_modes.sql
database/migrations/030_google_calendar_availability_modes_rollback.sql
docs/n8n_templates/template-agenda-google-espacos-livres.json
docs/n8n_templates/template-agenda-google-eventos-vago.json
docs/n8n_templates/payloads-agenda-google-exemplo.json
```

## Aplicação do ZIP

1. Faça backup do banco e do repositório atual.
2. Envie os arquivos deste ZIP ao GitHub, substituindo os arquivos da versão anterior.
3. Faça **Redeploy** no EasyPanel.
4. No Adminer, abra o banco do RS Connect e execute o conteúdo de:

```text
database/migrations/030_google_calendar_availability_modes.sql
```

5. Pressione `Ctrl + F5`.
6. Acesse:

```text
/agenda-inteligente
```

## Configuração do n8n

### Credencial Google

Nos dois workflows, selecione uma credencial OAuth2 do Google Calendar da empresa nos nós iniciados por **Google —**.

A credencial precisa ter acesso ao calendário configurado no RS Connect. Nenhuma chave ou credencial Google está incluída no ZIP.

### Fluxo 1 — espaços livres

Importe:

```text
docs/n8n_templates/template-agenda-google-espacos-livres.json
```

Depois:

1. Selecione a credencial Google no nó **Google — listar compromissos**.
2. Ative o workflow.
3. Copie a **Production URL** do webhook.
4. No RS Connect, selecione **Calcular espaços livres** e cole a URL no campo correspondente.

Esse fluxo considera eventos `opaque` como ocupados. Quando **Ignorar eventos transparent** estiver marcado, eventos definidos no Google como **Disponível** não bloqueiam o horário.

### Fluxo 2 — eventos VAGO

Importe:

```text
docs/n8n_templates/template-agenda-google-eventos-vago.json
```

Depois:

1. Selecione a mesma credencial Google nos três nós Google do fluxo:
   - **Google — listar eventos VAGO**;
   - **Google — reler evento**;
   - **Google — atualizar evento**.
2. Ative o workflow.
3. Copie a **Production URL** do webhook.
4. No RS Connect, selecione **Usar eventos VAGO** e cole a URL no campo correspondente.

O fluxo procura títulos exatos após normalizar acentos, espaços e tipos de traço. Os títulos padrão são:

```text
VAGO — ONLINE
VAGO — PRESENCIAL
```

## Teste do modo espaços livres

1. No Google Agenda, crie um compromisso como **Ocupado** das 14:00 às 15:00.
2. Configure no RS Connect expediente que inclua esse período.
3. Faça uma busca de disponibilidade.
4. O horário conflitante não deve aparecer.
5. Altere o evento no Google para **Disponível** e faça nova busca.
6. Com a opção de ignorar eventos transparent ativada, esse período volta a poder aparecer.

## Teste do modo eventos VAGO

1. No Google Agenda, crie:

```text
VAGO — ONLINE
```

2. Defina o evento como **Disponível**.
3. Faça a busca no RS Connect.
4. Clique em **Usar este horário**.
5. O mesmo evento deve virar algo como:

```text
PRÉ-RESERVADO — ONLINE — Nome do cliente
```

6. Aprove o pré-agendamento. O evento deve virar:

```text
AGENDADO — ONLINE — Nome do cliente
```

7. Ao cancelar ou liberar, o evento deve voltar ao título original VAGO e ao estado disponível.

## Segurança

- Configure um token secreto na Agenda inteligente e proteja os webhooks do n8n com autenticação por cabeçalho quando possível.
- O RS Connect envia o token no cabeçalho `Authorization` e em `X-RS-Connect-Token`.
- O callback usa um token exclusivo da solicitação, validado pelo RS Connect.
- Use uma credencial Google separada ou controlada para cada empresa/agenda.

## Observações

- No modo **eventos VAGO**, o fallback interno não é usado, pois não existe evento Google a ser atualizado.
- No modo **espaços livres**, o fallback interno continua disponível quando o n8n não responder.
- O campo **Intervalo entre inícios** define a distância entre o início de uma opção e a próxima. Exemplo: duração 60 e intervalo 60 gera opções iniciando a cada hora.
- A integração precisa ser validada no n8n e na conta Google reais depois do deploy.
