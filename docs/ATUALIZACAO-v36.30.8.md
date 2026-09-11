# RS Connect 36.30.8 — QA do fluxo central

## Objetivo

Fechar inconsistências encontradas no QA ponta a ponta do núcleo da operação, sem ampliar o escopo do produto.

Fluxo auditado:

`Contato → IA → Conversa → Fila/Setor opcional → Atendente → Agenda/CRM → Encerramento → Relatórios`

## Correções de ciclo

### Novo atendimento após uma conversa encerrada

A mesma linha de `conversations` pode ser reutilizada em ciclos diferentes. Antes desta versão, uma nova mensagem após o encerramento podia herdar estado transitório do ciclo anterior.

Na reabertura por nova mensagem, a aplicação agora:

- libera responsável anterior;
- em nova mensagem do cliente, volta `attendance_mode` para `ai`; uma saída feita diretamente pelo WhatsApp reabre em modo pausado para não disparar IA por engano;
- remove setor antigo;
- remove o agente de IA fixado no ciclo anterior;
- restaura prioridade `normal`;
- inicia o estado operacional como `new`;
- inicia as não lidas do novo ciclo sem somar resíduos antigos;
- reinicia `conversation_flow_states` e `conversation_triage_sessions`;
- cancela recuperação pós-horário ainda pendente do ciclo encerrado.

O histórico permanente de mensagens, contato, relacionamento, agenda e CRM é preservado.

### Encerramento consistente

Fechar uma conversa agora sempre a remove da operação ativa, independentemente de a empresa usar ou não o recurso opcional de responsável exclusivo:

- responsável é liberado;
- modo fica pausado enquanto encerrada;
- `operational_status` vira `resolved`;
- contador de não lidas é zerado.

A migration 110 normaliza conversas encerradas que já estavam inconsistentes no banco.

## Handoff humano

- intenção explícita de falar com humano passa a produzir o estágio `human_handoff` no fluxo;
- regras de Policy Engine configuradas como `handoff` deixam de apenas bloquear a IA e passam a colocar a conversa em `paused` + `waiting_agent`;
- o histórico da decisão continua registrado.

## Policy Engine por ciclo

A checagem de política já executada considera o início do ciclo ativo. Assim uma advertência legítima pode ser apresentada novamente em um atendimento futuro, sem apagar o histórico do atendimento anterior.

## Banco

Aplicar:

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Migration desta versão:

`110_conversation_lifecycle_e2e_consistency.sql`
