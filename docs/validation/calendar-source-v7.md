# Agenda — escolha da origem (v7)

A tela **Agenda > Horários e regras > Regras da agenda** agora permite escolher a fonte de disponibilidade:

- **Agenda interna do RS Connect**: consulta apenas compromissos, bloqueios e horários armazenados no RS Connect. Não chama n8n nem Google Agenda.
- **Google Agenda**: mantém o fluxo n8n/Google já configurado e permite usar espaços livres ou eventos `VAGO`.
- **Não utilizar agenda**: desativa a consulta automática de disponibilidade.

## Comportamento da Agenda interna

1. A empresa define dias e horários por dia da semana.
2. O pedido do lead é convertido em janela de busca preservando a hora informada.
3. `CalendarAvailabilityService` gera os slots localmente.
4. Conflitos com compromissos internos e, quando habilitado, agenda do profissional/contato são removidos.
5. O resultado é salvo com fonte `internal_fallback` por compatibilidade com o schema existente, mas a interface exibe **Agenda interna RS Connect**.
6. O payload de diagnóstico registra `calendar_source=internal`, `google_used=false` e `n8n_used=false`.

## Alternância sem perder a integração Google

A escolha da origem não apaga URLs, tokens, calendário ou regras técnicas do Google. Ao voltar para **Google Agenda**, o transporte n8n é reativado e a configuração existente é reutilizada.

A escolha também é sincronizada em `tenant_onboarding_settings.calendar_mode`, para que Prompt Studio e regras da IA usem a mesma fonte.
