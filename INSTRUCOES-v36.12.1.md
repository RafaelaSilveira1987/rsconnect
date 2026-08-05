# RS Connect v36.12.1 — Linguagem clara e diagnóstico simplificado

## Objetivo

Padronizar todos os avisos operacionais para responder rapidamente:

1. **O que aconteceu**
2. **O que pode ser afetado**
3. **O que fazer agora**

A tela do cliente não expõe nomes internos como Evolution, OpenAI, n8n, webhook, callback, migration ou cron. O Super Admin recebe a mesma explicação simples e pode abrir **Ver detalhes técnicos** quando precisar investigar.

## Instalação

Aplique este patch sobre a v36.12.0-r1 ou sobre a branch `hardening/beta-1.1` já atualizada.

```powershell
git switch hardening/beta-1.1
git pull origin hardening/beta-1.1
```

Extraia os arquivos na raiz do projeto, depois:

```powershell
git status
git add .
git commit -m "feat: simplificar linguagem de avisos e diagnosticos"
git push origin hardening/beta-1.1
```

Faça o Deploy/Rebuild no EasyPanel e atualize o navegador com `Ctrl + F5`.

## Banco de dados

Não existe migration nova. A última continua sendo:

`database/migrations/073_operational_monitoring_alert_delivery.sql`

## Validação

- abra **Avisos do sistema** e confirme os blocos “O que aconteceu”, “O que pode ser afetado” e “O que fazer agora”;
- confira uma notificação no painel do cliente e confirme que nomes técnicos não aparecem;
- abra **Ver detalhes técnicos** como Super Admin e confirme que código, serviço e mensagem original continuam disponíveis;
- teste o WhatsApp administrativo e confirme a mesma estrutura de leitura rápida;
- abra a saúde de uma empresa, a cópia de segurança e as respostas automáticas pendentes.

## Observação

A alteração é apenas de apresentação e comunicação. Códigos internos, eventos, integrações, tabelas e filtros técnicos permanecem inalterados.
