# RS Connect — ZIP 19

## Segurança do Sistema

Este pacote adiciona a primeira camada operacional de segurança do SaaS:

- novo menu Super Admin **Segurança**;
- log de tentativas de login;
- bloqueio por excesso de tentativas falhas;
- registro de sessões ativas;
- revogação de sessão pelo Super Admin;
- eventos de segurança com severidade;
- headers HTTP de segurança;
- expiração por sessão ociosa;
- checklist de tokens/chaves que precisam revisão;
- base para webhooks com modo estrito opcional;
- tabelas preparadas para auditoria e rotação de tokens.

## Atualização

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. Execute no Adminer:

```text
database/migrations/021_security_system.sql
```

4. Revise as variáveis opcionais:

```env
SECURITY_HEADERS_ENABLED=true
SECURITY_LOGIN_ATTEMPT_LIMIT=6
SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES=15
SECURITY_SESSION_IDLE_MINUTES=120
SECURITY_WEBHOOK_STRICT=false
```

5. Acesse como Super Admin:

```text
/ security
```

ou pelo menu **Segurança**.

## Observação importante

Mantenha `SECURITY_WEBHOOK_STRICT=false` até validar todos os webhooks da Evolution, n8n, pagamentos e régua de cobrança. Depois da validação, ele pode ser ativado gradualmente.

## Pós-instalação recomendado

Depois de aplicar este ZIP e confirmar que tudo funciona, revise/rotacione:

- token do webhook Evolution;
- token de callback do n8n;
- token do cron de cobrança;
- chaves de IA;
- chaves dos gateways de pagamento;
- senha do banco, caso tenha sido compartilhada em prints ou mensagens.

Não troque `APP_KEY` sem planejamento, porque ela é usada para criptografar credenciais já salvas.
