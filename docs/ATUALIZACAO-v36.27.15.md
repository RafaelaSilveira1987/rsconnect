# RS Connect 36.27.15 — agentes compactos e roteamento de agendamento

## Objetivo

Corrigir dois pontos observados na homologação comercial:

1. a tela **Assistentes Virtuais** ficava poluída e podia estourar horizontalmente com três ou mais agentes;
2. um agente com área `agendamento` criado anteriormente como **Distribuição automática** não assumia uma conversa já pinada no agente principal quando chegava uma intenção explícita como `Quero agendar uma demonstração`.

## Interface de agentes

Os cards agora mostram apenas o resumo operacional:

- nome e área;
- quantidade de canais;
- papel no atendimento;
- modelo;
- memória;
- status e modo automático/manual.

Informações técnicas e configurações extensas ficam recolhidas por padrão. O botão **Configurar** abre o bloco completo e leva diretamente ao roteamento por canal.

O grid usa largura mínima segura e quebra responsiva, evitando que um terceiro card seja cortado pela lateral da página.

## Roteamento de agendamento

A migration `101_agent_scheduling_specialist_routing.sql` é intencionalmente conservadora. Ela altera somente vínculos que atendem a todas as condições:

- agente ativo;
- vínculo ativo;
- não é o agente principal do canal;
- `routing_keywords` ainda está vazio;
- a área (`segment`) do agente contém `agend`.

Nesses casos, o vínculo recebe:

```text
agendar, agendamento, marcar, remarcar, reagendar, reservar
```

Regras explícitas já configuradas pelo usuário não são sobrescritas e nenhum nome de agente é hardcoded.

Com isso, uma conversa que estava com o principal pode transferir o pin para o agente de agendamento quando uma dessas intenções for recebida, usando o mecanismo de handoff IA→IA já existente.

## Novos agentes

Ao criar um assistente cuja área contém `agendamento`, a interface sugere automaticamente:

- papel: **Especialista por assunto**;
- palavras iniciais: `agendar, agendamento, marcar, remarcar, reagendar, reservar`.

A sugestão continua editável antes de salvar.

## Autoria das mensagens de agenda

As mensagens determinísticas do `PreSchedulingService`, como a pergunta de dia/horário, agora:

- usam o agente efetivamente roteado;
- enviam a assinatura correspondente no WhatsApp;
- persistem `sender_display_name` quando a coluna existe;
- mantêm o conteúdo limpo no histórico interno.

Isso evita que uma etapa de agenda continue aparecendo como enviada pelo agente principal depois da transferência para o especialista.

## Limpeza do pacote

Foram removidos apenas artefatos históricos/duplicados que não fazem parte da execução atual:

- instaladores de monitoramento 36.27.11–36.27.13;
- hotfixes antigos de white label;
- `.env.zip28.example` legado;
- cópias duplicadas dentro de `storage`;
- log vazio de distribuição e diretório `backup` vazio.

Código-fonte, migrations históricas, documentação, testes e scripts operacionais atuais foram preservados.

## Migration obrigatória

```text
database/migrations/101_agent_scheduling_specialist_routing.sql
```
