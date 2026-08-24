# Instalação e testes — ZIP 28

## 1. Aplicar no RS Connect

1. Copie o conteúdo deste pacote para a raiz do repositório do ZIP 27.
2. Mescle os arquivos da pasta `patches/` com o controller, as rotas e a view existentes.
3. Suba no GitHub e faça **Redeploy** no EasyPanel.
4. No Adminer, execute:

```text
database/migrations/030_google_calendar_availability_modes.sql
```

5. Pressione `Ctrl + F5`.

## 2. Configurar a credencial Google no n8n

Nos dois workflows, abra os nós Google/HTTP que pedem `Google Calendar OAuth2 API` e selecione a credencial da empresa. Em n8n self-hosted, use uma credencial Google Calendar OAuth2 configurada no Google Cloud.

Cada empresa deve usar seu próprio workflow/credencial ou uma segregação equivalente. Não compartilhe a agenda de uma empresa com outra.

## 3. Importar o fluxo de espaços livres

Importe:

```text
docs/n8n_templates/template-agenda-google-espacos-livres.json
```

Depois:

1. Selecione a credencial no nó **Google Calendar — listar eventos**.
2. Ative o workflow.
3. Copie a Production URL.
4. No RS Connect, selecione **Calcular pelos espaços livres da agenda**.
5. Cole a URL em **Webhook n8n — espaços livres**.

### Regra

- `opaque`/Ocupado: bloqueia o período;
- `transparent`/Disponível: não bloqueia;
- `interval_minutes`: pausa após cada atendimento. Duração 60 + intervalo 60 cria inícios a cada 120 minutos.

### Teste

1. Crie um evento Ocupado das 14:00 às 15:00.
2. Busque disponibilidade para a mesma data.
3. O horário 14:00 não deve aparecer.
4. Troque o evento para Disponível.
5. Busque novamente; 14:00 deve voltar.

## 4. Importar o fluxo de eventos VAGO

Importe:

```text
docs/n8n_templates/template-agenda-google-eventos-vago.json
```

Depois:

1. Abra os três nós HTTP do Google e selecione a credencial `Google Calendar OAuth2 API`.
2. Ative o workflow.
3. Copie a Production URL.
4. No RS Connect, selecione **Usar eventos marcados como VAGO**.
5. Cole a URL em **Webhook n8n — eventos VAGO**.

Crie no Google Agenda eventos com estes títulos:

```text
VAGO — ONLINE
VAGO — PRESENCIAL
```

Configure-os como **Disponível**, não Ocupado.

### Ciclo

```text
VAGO — ONLINE
→ PRÉ-RESERVADO — ONLINE — Maria
→ AGENDADO — ONLINE — Maria
```

Em cancelamento/liberação:

```text
PRÉ-RESERVADO/AGENDADO
→ VAGO — ONLINE
```

## 5. Testar pelo arquivo de payloads

Use exemplos de:

```text
docs/n8n_templates/payloads-exemplo.json
```

No n8n, clique em **Listen for test event** no Webhook e envie o JSON correspondente pelo Postman/Insomnia.

## 6. Validação esperada no RS Connect

Na busca, o callback para `/webhooks/calendar/availability` recebe:

- `source=google_free_slots` ou `source=google_marked_slots`;
- lista `slots`;
- no modo VAGO, `google_event_id` e `modality`.

Nas atualizações do modo VAGO, recebe:

- `event=calendar.marked_slot.updated`;
- `action=hold|confirm|release`;
- `state=held|confirmed|released`;
- `google_event_id`.

## 7. Concorrência

O fluxo de eventos VAGO relê o evento antes da alteração e envia o cabeçalho `If-Match` com o `etag`. Se outra pessoa alterar o evento entre a busca e a reserva, o Google retorna conflito em vez de sobrescrever silenciosamente.
