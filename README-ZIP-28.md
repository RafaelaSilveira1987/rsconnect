# RS Connect — ZIP 28

## Google Agenda: espaços livres + eventos VAGO

Este é um **pacote incremental para o ZIP 27**. Ele adiciona duas estratégias por empresa:

1. **Espaços livres:** lê os eventos do Google, ignora eventos `transparent` e calcula horários dentro das regras de expediente, duração, pausa e antecedência.
2. **Eventos VAGO:** localiza `VAGO — ONLINE` e `VAGO — PRESENCIAL`, devolve o `google_event_id` e permite pré-reservar, confirmar ou liberar o mesmo evento.

## Arquivos principais

- `database/migrations/030_google_calendar_availability_modes.sql`
- `docs/n8n_templates/template-agenda-google-espacos-livres.json`
- `docs/n8n_templates/template-agenda-google-eventos-vago.json`
- `docs/INSTALACAO-E-TESTES.md`
- `patches/` com os pontos de integração no PHP e na tela do ZIP 27.

## Observação importante

Como o ZIP 27 completo não foi anexado nesta conversa, os arquivos PHP foram entregues como **patches de integração**, para evitar sobrescrever rotas, controllers ou views com estruturas presumidas. A migration e os dois templates n8n são arquivos prontos para uso.
