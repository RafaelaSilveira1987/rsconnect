# Guia de filas e atendimento humano

## Estados principais

- **Aguardando horário:** mensagem preservada para retomada da automação.
- **Aguardando equipe:** conversa humana ou IA pausada recebida fora do expediente.
- **Retomando:** worker avaliando a pendência.
- **Aguardando franquia:** IA bloqueada pelo plano ou limite.
- **Nova tentativa:** houve falha temporária e existe reprocessamento programado.

## Assumir atendimento

Ao clicar em **Assumir e retirar da fila**:

1. A conversa passa para modo humano.
2. O usuário se torna responsável quando o bloqueio por profissional estiver ativo.
3. A pendência pós-horário muda para `cancelled`.
4. O worker não pode reabrir a pendência.
5. O banner e o filtro de fila desaparecem na próxima leitura.

## Bloqueio exclusivo

- O primeiro profissional que assume bloqueia a edição por outros usuários.
- Administradores podem transferir ou liberar.
- Outro atendente pode acompanhar, mas não responder.
- A transferência preserva todo o histórico.

## Liberação

Liberar remove o responsável e devolve a conversa à fila humana. A IA não retoma automaticamente. Para devolver à IA, use a ação explícita de modo.
