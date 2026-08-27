# Homologação — RS Connect 36.6.16

## 1. Scroll da Caixa de Entrada

- [ ] Abrir Conversas em desktop com uma lista extensa.
- [ ] Confirmar que somente a coluna de conversas rola verticalmente.
- [ ] Confirmar que o restante da página não cresce por causa da lista.

## 2. Scroll do histórico

- [ ] Abrir conversa com histórico extenso.
- [ ] Confirmar que somente o histórico rola verticalmente.
- [ ] Confirmar que o campo de digitação permanece visível.
- [ ] Enviar mensagem e confirmar permanência no final do histórico.

## 3. Aviso fora do horário por dia

Com `Responder somente no horário configurado` ativo:

- [ ] Em 25/07, enviar duas mensagens fora do expediente.
- [ ] Confirmar um único aviso de ausência naquele dia.
- [ ] Em 26/07, enviar nova mensagem ainda fora do expediente.
- [ ] Confirmar que um novo aviso de ausência é permitido em 26/07.
- [ ] Enviar outra mensagem no mesmo dia e confirmar que o aviso não repete.
- [ ] Confirmar aviso `mensagem preservada` e próxima janela no topo da conversa.

## 4. Tempo de espera de 60 segundos — primeira interação

Configurar `Tempo de espera da IA = 60`:

- [ ] Usar uma conversa sem resposta recente da IA.
- [ ] Enviar `Olá` às 10:00:00.
- [ ] Confirmar ausência de resposta antes de 10:01:00.
- [ ] Confirmar evento `ai.cooldown`/espera na automação.
- [ ] Confirmar resposta após o prazo + próxima execução da Fila rápida.

## 5. Reinício do relógio

- [ ] Enviar `Olá` às 10:00:00.
- [ ] Às 10:00:30 enviar `Tenho uma dúvida`.
- [ ] Confirmar que não responde em 10:01:00.
- [ ] Confirmar que a elegibilidade passa a ser a partir de 10:01:30.
- [ ] Confirmar que uma única resposta considera as duas mensagens.

## 6. Agenda respeitando a mesma espera

- [ ] Configurar espera de 60 segundos.
- [ ] Enviar `Quero agendar amanhã`.
- [ ] Confirmar que pré-agendamento não envia resposta fixa imediatamente.
- [ ] Após o prazo, confirmar que a Fila rápida reavalia a agenda e segue o fluxo correto.

## 7. Recuperação pós-horário

- [ ] Confirmar workflow Fila rápida da IA ativo e token válido.
- [ ] Deixar mensagens reais pendentes fora do horário.
- [ ] Aguardar a próxima abertura sem enviar nova mensagem.
- [ ] Confirmar recuperação automática da demanda.
- [ ] Confirmar que várias mensagens acumuladas resultam em uma única resposta contextual.
- [ ] Confirmar que modo Humano/Pausado impede recuperação automática.
- [ ] Confirmar que a recuperação automática não usa bypass do tempo de espera.

## 8. Smoke tests do pacote

```bash
php tests/Feature/agent-policy-reliability-smoke.php
php tests/Feature/after-hours-reply-timing-smoke.php
```

Os dois devem encerrar com `OK`.
