# RS Connect 36.27.2 — Multiagente configurável por canal

## Objetivo

Eliminar a necessidade de configurar `is_primary`, `priority` e `routing_keywords` diretamente no banco para cenários com múltiplos assistentes no mesmo WhatsApp.

## Interface

Na tela **Assistentes Virtuais**, cada vínculo com um canal passa a oferecer três papéis:

1. **Principal / recepção** — atendimento geral.
2. **Especialista por assunto** — recebe a conversa quando uma intenção configurada é identificada.
3. **Distribuição automática** — participa do round-robin de novas conversas gerais.

O card do assistente exibe o papel atual, as palavras de direcionamento e uma ação rápida **Configurar multiagente**.

## Motor de roteamento

- conversa já pinada mantém continuidade;
- keyword de outro especialista pode transferir o pin IA→IA;
- especialista com `routing_keywords` fica fora do pool genérico;
- principal e agentes de distribuição automática formam o pool genérico;
- se um canal legado tiver somente especialistas, o motor mantém fallback para não deixar o canal sem atendimento;
- round-robin transacional e takeover humano permanecem preservados.

## Banco

Nenhuma migration nova. A estrutura existente de `ai_agent_instance_bindings` e a migration `099_ai_agent_round_robin_routing.sql` são suficientes.

## Validação

- 378 arquivos PHP: `php -l` sem erros;
- 4 arquivos JavaScript: `node --check` sem erros;
- suíte focada de multiagente, handoff, vínculo, takeover e pós-horário aprovada;
- suíte Feature completa: 119 aprovados / 10 falhas históricas já conhecidas / 129 total.
