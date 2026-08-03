# Validação do hardening de segurança — RS Connect 36.11.1

## Objetivo

Confirmar que a aplicação mantém os fluxos homologados e aplica os novos controles de sessão, CSRF, autenticação e webhooks.

## 1. Sessão e login

1. Entre normalmente com um usuário ativo.
2. Navegue entre Conversas, Agenda e Relatórios.
3. Saia pelo botão de logout e tente voltar pelo histórico do navegador.
4. Resultado esperado: a área autenticada não volta a abrir e o login é solicitado.
5. Desative um usuário de teste enquanto ele estiver conectado e atualize uma página protegida.
6. Resultado esperado: a sessão é encerrada no próximo acesso.

Evite testar bloqueio com a única conta administrativa. Use uma conta de teste. Após seis senhas incorretas dentro da janela configurada, a conta deve ser temporariamente bloqueada. Tentativas distribuídas por vários e-mails no mesmo IP também devem atingir o limite global.

## 2. Cookie de sessão

No DevTools, abra **Application/Storage → Cookies** e confira o cookie `rs_connect_session`:

- `HttpOnly`: ativo;
- `Secure`: ativo na VPS HTTPS;
- `SameSite`: `Lax`;
- o ID deve mudar após novo login e periodicamente conforme `SECURITY_SESSION_ROTATE_MINUTES`.

## 3. CSRF

1. Abra um formulário autenticado e envie normalmente.
2. Resultado esperado: operação concluída.
3. No DevTools, remova ou altere o campo `_token` e reenvie.
4. Resultado esperado: HTTP 419/redirecionamento com mensagem de sessão expirada; nenhuma alteração deve ocorrer no banco.
5. Repita no login para confirmar que a tela é recarregada com um token novo.

## 4. Evolution

No primeiro deploy, mantenha `SECURITY_WEBHOOK_STRICT=false` e confirme que mensagens continuam chegando.

Depois:

1. Gere um token longo e exclusivo.
2. Configure o mesmo valor em `EVOLUTION_WEBHOOK_TOKEN` e no webhook da Evolution, usando `X-Webhook-Token`, `X-RS-Connect-Token`, Bearer ou query `token` conforme sua configuração atual.
3. Altere `SECURITY_WEBHOOK_STRICT=true` e faça redeploy.
4. Envie uma mensagem real para a instância.
5. Resultado esperado: mensagem recebida normalmente.
6. Faça uma chamada de teste sem token.
7. Resultado esperado: HTTP 401 e evento `webhook.token_invalid` ou `webhook.token_missing_config`.

## 5. n8n

Quando o callback global estiver em uso:

1. Configure `N8N_CALLBACK_TOKEN` no RS Connect.
2. Envie o mesmo token pelo n8n em `X-RS-Connect-Token` ou `Authorization: Bearer`.
3. Confirme callback válido com HTTP 200.
4. Remova o token em uma execução de teste.
5. Resultado esperado: HTTP 401.

Os crons de backup, monitoramento, cobrança, IA, calendário, saúde e retenção continuam usando seus tokens específicos.

## 6. Gateways de pagamento

Antes do modo estrito, abra **Gateways de pagamento** e confirme um segredo de webhook em cada gateway automático ativo. Faça um evento de homologação do provedor e confirme que a cobrança correta é atualizada. Um segredo ausente no modo estrito deve impedir o processamento.

Não ative o modo estrito em produção antes de homologar o formato aceito pelo gateway utilizado.

## 7. Limite de webhooks

- corpo acima de `SECURITY_WEBHOOK_MAX_BYTES`: HTTP 413;
- frequência acima do limite configurado: HTTP 429 e header `Retry-After`;
- chamadas normais abaixo do limite: continuam processadas.

Os eventos de bloqueio ficam em `security_events`.

## 8. Diagnóstico SQL

Execute `database/diagnostics/security_hardening_v36.11.1.sql`.

Esperado:

- quatro tabelas de segurança presentes;
- `security_rate_limits` disponível;
- nenhum bloqueio antigo anormal;
- eventos recentes compatíveis com os testes executados;
- tentativas de login registradas com IP e motivo.

## 9. Regressão funcional mínima

Confirme após o deploy:

- login e logout;
- recebimento e envio de WhatsApp;
- abertura manual de conversa;
- agenda e disponibilidade;
- relatório de equipe;
- execução de um cron n8n já homologado;
- acesso do Super Admin;
- isolamento entre duas empresas, já validado na v36.11.0.
