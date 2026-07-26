# RS Connect 36.6.16 — Conversas compactas, aviso diário e tempo de interação

## Objetivo

Melhorar a Caixa de Entrada e fechar duas regras de confiabilidade do atendimento automático:

1. a mensagem fixa de ausência pode ser enviada novamente em um novo dia fora do horário;
2. o tempo configurado do agente passa a ser contado a partir da **última mensagem recebida**, inclusive na primeira interação.

## O que muda

### Conversas

No desktop, a área de Conversas passa a ocupar a altura disponível da tela:

- a coluna de conversas possui scroll próprio;
- o histórico da conversa possui scroll próprio;
- cabeçalho, estado e campo de digitação permanecem no lugar;
- a página inteira deixa de crescer conforme o histórico aumenta.

Em telas menores, o layout responsivo anterior continua valendo.

### Fora do horário: aviso por dia

Quando `Responder somente no horário configurado` está ativo, a IA principal, agenda e automações conversacionais internas ficam bloqueadas fora do expediente.

A mensagem fixa de ausência é deduplicada por **dia local do agente**, e não por toda a janela fechada.

Exemplo:

- 25/07: primeira mensagem fora do horário → envia o aviso;
- 25/07: novas mensagens → não repete;
- 26/07: primeira nova mensagem fora do horário → pode enviar o aviso novamente;
- 26/07: demais mensagens → não repete.

Todas continuam vinculadas à mesma pendência para recuperação no próximo período comercial válido.

### Tempo de espera da IA

O campo técnico `cooldown_seconds` continua no banco para compatibilidade, mas na interface passa a ser apresentado como **Tempo de espera da IA (seg.)**.

A regra agora é:

- a IA só pode responder depois de transcorrer o tempo configurado desde a **última mensagem recebida**;
- a primeira interação também respeita esse tempo;
- se o cliente enviar outra mensagem durante a espera, o relógio reinicia;
- as mensagens acumuladas entram juntas no contexto e geram uma única resposta;
- a Fila rápida reavalia a conversa após o prazo;
- somente reprocessamento manual explícito pode ignorar a espera;
- a recuperação automática pós-horário não ignora a espera.

Exemplo com 60 segundos:

1. 10:00:00 — cliente envia `Olá`;
2. 10:00:30 — cliente envia `Tenho uma dúvida`;
3. o relógio reinicia em 10:00:30;
4. nenhuma resposta automática deve ocorrer antes de 10:01:30;
5. após a fila reavaliar, a IA responde considerando as duas mensagens.

A camada de agenda também é adiada enquanto essa janela estiver ativa. Depois do prazo, a Fila rápida reexecuta a interpretação de agenda antes de chamar a IA geral.

### Recuperação pós-horário

Quando existe uma pendência preservada, o topo da conversa mostra:

`Fora do horário · mensagem preservada · retoma a partir de DD/MM HH:mm`

A rotina utiliza `ai_after_hours_pending` e é executada pela Fila rápida da IA e/ou pelo Monitor operacional.

Para funcionar automaticamente, confirme:

- `AI_REPROCESS_CRON_TOKEN` configurado;
- workflow **Fila rápida da IA** ativo no n8n; ou Monitor operacional ativo;
- conversa em modo IA no momento da retomada;
- agente ativo e com resposta automática ligada;
- conexão WhatsApp disponível;
- franquia aplicável disponível.

## Banco

Não há migration nova. A última migration continua sendo `055_multi_whatsapp_agent_routing.sql`.
