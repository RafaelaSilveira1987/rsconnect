# RS Connect 36.27.4 — Identificação do agente no WhatsApp

## Objetivo
Tornar visível ao cliente final qual assistente virtual respondeu cada mensagem em conversas individuais do WhatsApp.

## Comportamento
- Agente geral/principal: `*IA - Digi*` na primeira linha.
- Agente especialista: `*IA Comercial - Carlos*`, usando a área/segmento cadastrada no agente.
- A assinatura é adicionada apenas ao texto entregue à Evolution/WhatsApp. O conteúdo armazenado no painel permanece limpo e usa `sender_display_name`.
- O mecanismo é genérico para futuros agentes e especialistas.
- Retry de resposta automática preserva o emissor identificado.

## Compatibilidade
Sem migration nova. Mantém a migration obrigatória 099 e o motor multiagente 36.27.x.
