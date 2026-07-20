# RS Connect — HOTFIX 36.1.1

## Correção aplicada

A fila anterior reconhecia somente `ai.cooldown`. Quando a geração da resposta ou o envio pela Evolution falhava, o evento ficava como `ai.failed` e a conversa desaparecia da fila.

O hotfix passa a:

- vincular o log da IA ao ID interno da mensagem recebida;
- considerar `ai.cooldown` e `ai.failed` como pendência;
- recolocar na fila respostas aceitas inicialmente, mas depois marcadas como `failed` pela Evolution;
- detectar processamento interrompido após a mensagem ser gravada;
- impedir novo envio quando já existe uma saída posterior;
- manter a falha visível para nova tentativa;
- suportar assistentes padrão, inclusive sem `instance_id` fixo.

## Atualização obrigatória

Após subir os arquivos, execute:

```text
database/migrations/044_ai_pending_failures_message_link.sql
```

A migration 043 continua necessária para a tela e o agendamento geral.

## Teste recomendado

1. Envie uma mensagem e aguarde a resposta.
2. Envie outra mensagem.
3. Caso o provedor ou a Evolution falhe, aguarde dois minutos.
4. Abra **Central de operação > Fila da IA**.
5. A mensagem deverá aparecer como pendente.
6. Clique em **Reprocessar pendências agora**.

Se a nova tentativa também falhar, a mensagem continua na fila e o erro permanece registrado nos logs; ela não é descartada nem reenviada em duplicidade.
