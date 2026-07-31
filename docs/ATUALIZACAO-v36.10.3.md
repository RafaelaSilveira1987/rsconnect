# Atualização RS Connect v36.10.3

## Objetivo

Sincronizar o status real da conversa com o ciclo usado nos relatórios por profissional.

## Problema corrigido

Na homologação, a conversa `1104` mudou visualmente para Encerrada, mas o ciclo continuou ativo e sem `closed_at`. A v36.10.3 corrige o problema no backend e no banco.

## Instalação

1. Aplicar o patch sobre a v36.10.2.
2. Manter `log_bin_trust_function_creators = ON` temporariamente.
3. Importar `database/migrations/070_conversation_cycle_status_sync_compat.sql`.
4. Fazer deploy/rebuild.
5. Validar com `database/diagnostics/conversation_cycle_status_sync_v36.10.3.sql`.
6. Após a criação do trigger, retornar `log_bin_trust_function_creators` para `OFF`.

## Resultado esperado

Ao encerrar:

- `conversations.status = closed`;
- o último ciclo ativo muda para `closed`;
- `closed_at` e `closed_by_user_id` são preenchidos.

Ao reabrir:

- o ciclo anterior permanece fechado;
- um novo `cycle_number` é criado como ativo.
