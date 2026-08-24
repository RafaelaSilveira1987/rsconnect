# RS Connect — Homologação Final v36.5.6

Data do checkpoint: 23/07/2026.

Este arquivo registra o ponto de fechamento do SaaS e deve ser atualizado a cada rodada de homologação.

## Resultado da primeira rodada

| # | Área | Situação | Próxima ação |
|---|---|---|---|
| 1 | Acesso e empresa | OK | Nenhuma. |
| 2 | Minha empresa e usuários | OK | Nenhuma. |
| 3 | Isolamento multiempresa | OK | Manter no teste de regressão final. |
| 4 | WhatsApp / Evolution | OK | Manter teste de regressão. |
| 5 | Tags, classificação e contexto | CORRIGIDO NA 36.5.6 | Retestar Cliente atual sem reiniciar triagem e validar identificação de novos contatos. |
| 6 | IA x atendimento humano | CORRIGIDO NA 36.5.6 | Retestar Assumir atendimento durante geração de resposta. |
| 7 | Reprocessamento da IA | CORRIGIDO NA 36.5.6 | Confirmar botão sempre disponível no diagnóstico RS. |
| 8 | Comercial | OK | Nenhuma. |
| 9 | Agenda | OK PRELIMINAR | Fazer validação aprofundada posteriormente. |
| 10 | Relatórios | OK | Conferir novamente após novos dados da homologação. |
| 11 | Régua / cron de cobrança | CONFIGURAÇÃO PENDENTE | Importar e ativar o template Cron da régua de cobrança no n8n e executar uma vez. |
| 12 | Backup | OK | Manter teste de restauração como rotina periódica. |
| 13 | Segurança de produção | OK | Revalidar antes do go-live. |
| 14 | Mobile | CORRIGIDO NA 36.5.6 | Retestar drawer de contatos, Automações e Conexões WhatsApp em celular real. |

## Correções incluídas na 36.5.6

- Classificação `customer` passa a prevalecer quando o contato ainda estava no grupo `interested`/`unclassified`.
- Novo grupo lógico `customer` / Cliente atual, com agenda sem exigência automática de repetir demanda.
- Regras e prompt da IA deixam explícito que cliente atual não deve reiniciar o fluxo de novo interessado.
- Webhook Evolution preserva nomes corrigidos manualmente e trata `remoteJidAlt` quando necessário.
- Takeover humano revalida o modo da conversa antes do envio da resposta gerada pela IA.
- Botão Reprocessar IA permanece disponível no diagnóstico dos assistentes.
- Fluxos n8n recuperados deixam de permanecer críticos apenas por erros anteriores nas últimas 24h.
- Eventos consumidos pela agenda aparecem como concluídos no histórico de Automações quando a agenda tratou a mensagem.
- Execução manual da régua não é mais confundida com heartbeat do cron automático.
- Template `billing-cron` baixado pelo painel recebe `APP_URL` e `BILLING_CRON_TOKEN` do ambiente.
- Drawers usam altura dinâmica de viewport e o rodapé de salvar permanece acessível em mobile.
- Correções de overflow horizontal nas telas do cliente, especialmente Conexões WhatsApp.

## Critério para liberar v1

- Zero bloqueadores de segurança, isolamento, perda/duplicação de dados, WhatsApp, IA, agenda ou financeiro.
- Itens 5, 6, 7 e 14 retestados após subir a 36.5.6.
- Cron da cobrança com pelo menos um heartbeat automático recente identificado como `Régua (cron)`.
