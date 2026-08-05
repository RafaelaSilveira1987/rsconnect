# RS Connect 36.11.1 — Segurança de sessão, CSRF, login e webhooks

## Base necessária

Aplique este pacote sobre a **v36.11.0** já homologada na branch `hardening/beta-1.1`.

## 1. Arquivos

Extraia o patch na raiz do projeto, preservando a estrutura de pastas.

## 2. Banco de dados

Execute uma única vez:

```text
database/migrations/072_security_session_webhook_hardening.sql
```

A migration é idempotente e cria `security_rate_limits`.

## 3. Variáveis de ambiente

Acrescente ao `.env` real da VPS:

```dotenv
SESSION_SAMESITE=Lax
SESSION_COOKIE_SECURE=true
TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128

SECURITY_HEADERS_ENABLED=true
SECURITY_CSP_ENABLED=true
SECURITY_LOGIN_ATTEMPT_LIMIT=6
SECURITY_LOGIN_IP_LIMIT=30
SECURITY_LOGIN_ATTEMPT_WINDOW_MINUTES=15
SECURITY_SESSION_IDLE_MINUTES=120
SECURITY_SESSION_ABSOLUTE_MINUTES=720
SECURITY_SESSION_ROTATE_MINUTES=30
SECURITY_SESSION_BIND_USER_AGENT=true
SECURITY_CSRF_TTL_MINUTES=120
SECURITY_CSRF_ORIGIN_CHECK=true
SECURITY_WEBHOOK_MAX_BYTES=5242880
SECURITY_WEBHOOK_RATE_LIMIT_PER_MINUTE=600
SECURITY_CRON_RATE_LIMIT_PER_MINUTE=60
SECURITY_WEBHOOK_STRICT=false
```

Mantenha `SECURITY_WEBHOOK_STRICT=false` no primeiro deploy. Depois de confirmar os tokens abaixo, altere para `true` e faça novo redeploy:

- `EVOLUTION_WEBHOOK_TOKEN` configurado no RS Connect e no webhook da Evolution;
- `N8N_CALLBACK_TOKEN` quando `/webhooks/n8n/callback` estiver em uso;
- segredo de webhook em cada gateway de pagamento automático ativo.

Os demais endpoints de cron continuam exigindo seus próprios tokens: backup, monitor, cobrança, fila de IA, agenda, saúde dos clientes e retenção de mensagens.

## 4. Deploy

```powershell
git switch hardening/beta-1.1
git status
git add .
git commit -m "security: reforcar sessao csrf login e webhooks"
git push origin hardening/beta-1.1
```

Faça o rebuild no EasyPanel. Sessões antigas podem pedir um novo login após a troca dos parâmetros do cookie; isso é esperado.

## 5. Validação

Execute:

```text
database/diagnostics/security_hardening_v36.11.1.sql
```

Depois siga:

```text
docs/diagnostics/SECURITY-HARDENING-VALIDATION-v36.11.1.md
```

## 6. Reversão

Em caso de incompatibilidade operacional, volte o serviço para a tag/branch protegida anterior. A tabela `security_rate_limits` pode permanecer no banco sem afetar a v36.11.0.
