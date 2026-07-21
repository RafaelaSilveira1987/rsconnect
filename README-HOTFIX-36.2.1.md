# HOTFIX 36.2.1 — Agenda sem queda para IA

## Correção

Ao escolher uma alternativa de horário, a resposta é consumida integralmente pela agenda. O evento de saída `SEND_MESSAGE` da Evolution não é mais interpretado como nova entrada e a seleção recebe um marcador `ai.skipped`.

## Instalação

1. Aplicar os arquivos do hotfix sobre o ZIP 36.2.
2. Fazer commit e redeploy.
3. Não executar migration adicional.
4. Confirmar `HOTFIX 36.2.1` em Central de operação > Status do sistema.

## Teste

1. Solicitar um horário ocupado.
2. Receber as opções.
3. Responder `1`.
4. Confirmar a pré-reserva e ausência de `ai.failed`.

## Diagnóstico

Execute `database/diagnostics/calendar_selection_ai_isolation_check.sql`.
