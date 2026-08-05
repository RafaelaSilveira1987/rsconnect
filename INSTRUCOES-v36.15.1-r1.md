# RS Connect v36.15.1-r1 — Correção do envio de relatórios pelo WhatsApp

## Problema corrigido

Com `PDO::ATTR_EMULATE_PREPARES = false`, o MySQL não aceita que o mesmo parâmetro nomeado seja reutilizado mais de uma vez na mesma consulta preparada.

A v36.15.1 reutilizava `:now` em atualizações de entrega e programação, causando:

`SQLSTATE[HY093]: Invalid parameter number`

## Correção

Os parâmetros foram separados em nomes exclusivos, como:

- `:last_attempt_at`
- `:sent_at`
- `:updated_at`
- `:last_run_at`

## Instalação

Aplique o patch sobre a v36.15.1 e faça novo deploy.

Não há migration nova. A última continua sendo:

`075_scheduled_reports_and_deliveries.sql`

## Teste

1. Gere um relatório com destinatário no formato `Nome | 5532999999999`.
2. Marque o envio imediato.
3. Confirme a situação `Enviado`.
4. Use o botão de reenvio.
5. Execute o fluxo do n8n duas vezes.
