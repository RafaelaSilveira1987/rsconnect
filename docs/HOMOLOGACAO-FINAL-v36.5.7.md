# RS Connect — Homologação Final v36.5.7

Data do checkpoint: 23/07/2026.

Este arquivo registra o ponto de fechamento do SaaS e deve ser atualizado a cada rodada de homologação.

## Resultado da primeira rodada

| # | Área | Situação | Próxima ação |
|---|---|---|---|
| 1 | Acesso e empresa | OK | Nenhuma. |
| 2 | Minha empresa e usuários | OK | Nenhuma. |
| 3 | Isolamento multiempresa | OK | Manter no teste de regressão final. |
| 4 | WhatsApp / Evolution | OK | Manter teste de regressão. |
| 5 | Tags, classificação e contexto | RETESTE NA 36.5.7 | Retestar Cliente atual sem reiniciar triagem e validar identificação de novos contatos. |
| 6 | IA x atendimento humano | RETESTE NA 36.5.7 | Retestar Assumir atendimento durante geração de resposta. |
| 7 | Reprocessamento da IA | RETESTE NA 36.5.7 | Confirmar botão sempre disponível no diagnóstico RS. |
| 8 | Comercial | OK | Nenhuma. |
| 9 | Agenda | OK PRELIMINAR | Fazer validação aprofundada posteriormente. |
| 10 | Relatórios | OK | Conferir novamente após novos dados da homologação. |
| 11 | Régua / cron de cobrança | PRONTO PARA ATIVAÇÃO | Configurar token, baixar/importar o template, ativar no n8n e executar uma vez. |
| 12 | Backup | OK | Manter teste de restauração como rotina periódica. |
| 13 | Segurança de produção | OK | Revalidar antes do go-live. |
| 14 | Mobile | RETESTE NA 36.5.7 | Retestar drawer de contatos, Automações e Conexões WhatsApp em celular real. |

## Correções consolidadas até a 36.5.7

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


## Ajustes específicos da 36.5.7

- O `pushName` recebido da Evolution é ignorado quando coincide com o nome de um usuário ativo da própria empresa. Isso evita que novos contatos sejam cadastrados como o administrador da conta.
- Contatos já salvos anteriormente com nome incorreto não são apagados nem alterados automaticamente, para não sobrescrever possíveis edições humanas; devem ser revisados pela tela de Contatos.
- Controles móveis recebem `touch-action: manipulation` para resposta mais imediata ao toque.
- O cron financeiro só pode ser exposto quando `BILLING_CRON_TOKEN` está configurado e o template só é baixado com `APP_URL` HTTPS.
