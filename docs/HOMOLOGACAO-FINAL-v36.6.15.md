# Homologação — RS Connect 36.6.15

## 1. Horário como trava técnica

Configure um agente para:

- **Responder somente no horário configurado**: ativo;
- segunda a sexta;
- 08:00 às 18:00;
- timezone correto da empresa.

Fora desse período, envie uma mensagem comum.

Esperado:

- mensagem recebida e armazenada;
- nenhuma resposta de agenda;
- nenhuma seleção automática de horário;
- nenhuma resposta da IA além da mensagem de ausência configurada;
- mensagem de ausência no máximo uma vez na mesma janela;
- pendência preservada para recuperação.

## 2. Prompt 24h x regra operacional

Mesmo que o prompt diga que o agente pode atender/qualificar 24h, mantenha **Responder somente no horário configurado** ativo.

Esperado: a trava técnica vence o prompt e o agente não continua a conversa fora do expediente.

Depois desative a trava e repita.

Esperado: o prompt pode operar 24h normalmente.

## 3. Falso gatilho de agenda

Sem contexto de agenda, envie:

> Vou tentar configurar hoje a tarde/noite

Esperado: não iniciar agenda.

Envie:

> Qual o horário de atendimento de vocês?

Esperado: tratar como pergunta de funcionamento, não agenda.

Envie:

> Preciso das 10 maiores empresas em Cuiabá

Esperado: continuar o assunto geral, sem resposta de agendamento.

## 4. Agenda real

Envie:

> Quero agendar uma reunião amanhã à tarde

Esperado: intenção de agenda reconhecida e fluxo de agenda permitido, desde que regras do contato também permitam.

Em uma conversa sem contexto prévio, envie apenas:

> Pode ser terça às 15h

Esperado: não abrir agenda isoladamente.

Depois inicie um agendamento real e, quando o sistema pedir preferência, envie a mesma frase.

Esperado: reconhecer como continuação da agenda.

## 5. Cliente/Paciente atual

Marque um contato como **Cliente** ou **Paciente atual** e salve.

Pergunte algo geral e depois uma remarcação.

Esperado:

- não perguntar novamente se é cliente;
- não pedir principal queixa/motivo como condição;
- manter continuidade do relacionamento;
- classificação exibida em **Regras aplicadas agora**.

## 6. Tags e grupo

Adicione tags e altere o grupo do contato.

Esperado: o drawer da conversa deve mostrar os valores atuais e a IA deve recebê-los como contexto estruturado.

## 7. Humano/Pausado

Coloque a conversa em **Humano**.

Envie:

> Quero agendar amanhã

Esperado: nenhuma automação responde.

Repita com modo **Pausado**.

## 8. Callback tardio da agenda

Inicie uma consulta de disponibilidade perto do encerramento do horário e faça o retorno/callback ocorrer quando o expediente já estiver fechado.

Esperado:

- não enviar opções fora do horário;
- não pré-reservar automaticamente fora do horário;
- demanda ser preservada para recuperação posterior.

## 9. Drawer de diagnóstico

Em **Conversas → Dados da conversa**, confirme a seção **Validação efetiva / Regras aplicadas agora**.

Valide:

- agente;
- modo;
- horário;
- classificação;
- grupo;
- tags;
- intenção;
- contexto de agenda.

Esses dados devem coincidir com a configuração real antes de avaliar a resposta da IA.

## 10. Smoke test do pacote

Opcionalmente execute no container da aplicação:

```bash
php tests/Feature/agent-policy-reliability-smoke.php
```

Esperado:

```text
OK - politica de horario, intencao de agenda, contexto geral e classificacao validados.
```

## Regressão

- envio humano continua pausando automação;
- cooldown continua valendo;
- múltiplos canais/agentes da 36.6.13 continuam funcionando;
- planos da 36.6.14 permanecem inalterados;
- não há migration nova.
