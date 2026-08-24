# RS Connect 36.6.34.2 — Agenda interna e liberação da Agenda inteligente

Este pacote já inclui integralmente:

- RS Connect 36.6.34 — teste gratuito e primeiro acesso guiado;
- RS Connect 36.6.34.1 — hotfix de login e sessão;
- RS Connect 36.6.34.2 — escolha explícita do tipo de agenda no onboarding.

Não é necessário implantar o ZIP 36.6.34.1 separadamente.

## Atualização

1. Grave um ponto de release/backup antes do deploy.
2. Publique o ZIP `rs-connect-vps-ready-36.6.34.2.zip`.
3. Execute, nesta ordem, as migrations ainda não aplicadas:

```sql
SOURCE database/migrations/060_free_trial_guided_first_access.sql;
SOURCE database/migrations/061_onboarding_calendar_modes.sql;
```

As duas migrations são idempotentes e podem ser executadas na ordem acima.

4. Faça novo login e use `Ctrl + F5`.

## O que muda na Etapa 4 do onboarding

O cliente precisa escolher uma opção:

- **Não utilizar agenda** — não consulta nem registra horários;
- **Agenda interna do RS Connect** — disponibilidade e conflitos são tratados no banco da plataforma, sem n8n e sem Google Calendar;
- **Agenda inteligente integrada** — somente aparece liberada após homologação pelo Super Admin.

## Agenda interna

Ao selecionar Agenda interna, o cliente configura:

- dias ativos;
- início e fim por dia;
- duração padrão;
- intervalo entre opções;
- intervalo de segurança;
- antecedência mínima;
- dias futuros pesquisados;
- quantidade máxima de sugestões.

O backend força:

```text
use_n8n = 0
use_internal_fallback = 1
create_google_event_on_confirm = 0
require_google_sync_on_confirm = 0
```

As URLs e credenciais técnicas existentes não são expostas ao cliente.

## Agenda inteligente

O Super Admin libera em:

```text
Empresas → Configurações da empresa → Agenda inteligente
```

Estados disponíveis:

- Não liberada;
- Em configuração pela RS;
- Liberada e homologada.

Somente o último estado torna a opção selecionável no onboarding.
