# Homologação final — RS Connect 36.6.5

## Objetivo
Validar resolução contextual, alertas operacionais do Super Admin e comunicados para clientes.

## Pré-requisito
Aplicar `database/migrations/049_operational_resolution_communications.sql` após as migrations anteriores.

## 1. Painel → correção contextual
1. Abra **Operação RS → Painel operacional**.
2. Execute **Verificar sistema agora**.
3. Em um problema ativo, clique **Resolver problema**.
4. Confirme que a Central de operação abre com o card **Assistente de correção**.
5. Valide título, causa, impacto, passos, evidência técnica e atalhos.
6. Para ocorrência de empresa, valide **Avisar empresa**.

## 2. Playbooks prioritários
Validar pelo menos:
- Evolution desconectada;
- n8n/callback/token;
- OpenAI 401, 403, 429 ou quota quando houver evidência correspondente;
- Backup atrasado/falha;
- Cron de cobrança atrasado.

## 3. Execução automática do monitoramento
1. Configure `OPERATIONS_MONITOR_TOKEN` no ambiente (se vazio, o sistema aceita `OPERATIONS_BACKUP_TOKEN` como fallback).
2. Em **n8n → Templates**, baixe **Monitor operacional RS Connect**; o download injeta a `APP_URL` e o token configurados.
3. Importe/ative o workflow. Ele chama `POST /webhooks/operations/checks/run` com o header `X-RS-Connect-Token` a cada 15 minutos.
4. Confirme retorno JSON `ok: true` e criação/atualização dos alertas.

## 4. Alertas do Super Admin
1. Abra **Operação RS → Alertas operacionais**.
2. Salve preferências e intervalo de lembrete.
3. Gere uma verificação com `warning`/`down`.
4. Confirme alerta no sino do Super Admin e na central de alertas.
5. Repita a verificação antes do cooldown e confirme que não há spam.
6. Normalize a causa e rode nova verificação.
7. Confirme alerta **Resolvido**.

> WhatsApp e e-mail são exibidos como `pending_configuration` nesta versão até o provedor administrativo da RS ser conectado.

## 5. Comunicados
1. Abra **Operação RS → Comunicados**.
2. Selecione uma empresa, informe tipo/título/mensagem e envie.
3. Entre como cliente e confirme a notificação no sininho.
4. Marque a notificação como lida e volte ao histórico de Comunicados.
5. Confirme aumento do contador de leituras.
6. Repita para múltiplas empresas e para **Todas as empresas**.

## 6. Segurança de comunicação
- Detalhes como token, stack trace, HTML de erro e credenciais não devem ser incluídos automaticamente em comunicados ao cliente.
- O botão **Avisar cliente** apenas pré-seleciona destinatário/tipo; o texto continua sob revisão humana.
- Falha do WhatsApp do cliente não impede o comunicado in-app.

## Critério de aceite
A versão está homologada quando o ciclo **detectar → orientar → corrigir → alertar → recuperar → comunicar** funciona sem duplicar incidentes nem registrar falso envio em canais externos.
