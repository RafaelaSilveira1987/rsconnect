# RS Connect — Hotfix da ativação: acesso ao serviço de respostas

**Escopo:** diagnóstico do Validador de Prontidão ao ativar um agente, na tela `/agents`.

## Sintoma

Mensagem: `Ativação impedida pelo Validador de Prontidão: Credencial de IA.`

## Causa localizada no pacote anterior

O `AgentReadinessService` considerava configurada uma chave **apenas** quando encontrava um registro `active` em `ai_provider_credentials`. O motor real (`AiModelService`) também aceita chave no ambiente do servidor, quando não há chave criptografada na credencial selecionada. Assim, uma chave global do EasyPanel pode funcionar no atendimento e ser ignorada pelo validador.

## Solução

1. `AgentCredentialAvailabilityService` replica a precedência do atendimento: credencial específica do agente; credencial compartilhada da empresa; fallback do ambiente para o **mesmo provedor**.
2. A leitura do banco mantém isolamento de empresa e status `active`; não consulta registros de outras empresas.
3. O serviço não retorna a API Key, apenas disponibilidade, origem e mensagem segura.
4. Não basta haver um registro ativo: se a chave criptografada não puder ser lida com `APP_KEY`, o sistema **bloqueia** a ativação (como o motor de atendimento faria).
5. Se o modelo do agente usar Google, uma chave `OPENAI_API_KEY` **não** o habilita; e vice-versa.
6. Continua exigindo todos os demais requisitos críticos do Readiness Score e **não** ativa automaticamente o agente.

## Arquivos afetados

- `app/Services/AgentCredentialAvailabilityService.php` (novo)
- `app/Services/AgentReadinessService.php`
- `app/Services/AgentReadinessEvaluator.php`
- `app/Controllers/AgentController.php` (mensagem amigável)
- `tests/Feature/agent-credential-source-readiness-oct2026-smoke.php` (novo)

Nenhuma tabela nova; sem migration.

## Como instalar

1. Faça backup completo (código e banco).
2. Confirme que o RS Connect instalado é a versão **Correção de Salvamento, Prontidão e Layout** de 14/10/2026.
3. Aplique os arquivos do ZIP **patch** na raiz do projeto, preservando as pastas internas.
4. Atualize o cache PHP/OPcache ou reinicie o serviço PHP da instalação, se utilizado.
5. Recarregue `/agents` e tente ativar novamente.
6. Confirme no endpoint `GET /api/v1/agents/{uuid}/readiness` a linha **Serviço de respostas**. Não publique o relatório inteiro se contiver informações específicas do negócio.
7. Faça um teste real de resposta no laboratório e de uma conversa de homologação antes de usar com clientes.

## Diagnóstico se ainda houver bloqueio

- Como administrador, abra **Assistentes e chaves de acesso** (`/ai-credentials`) e confira empresa, agente, provedor e status **ativo**.
- Confira o **provedor** selecionado no agente: `openai` requer `OPENAI_API_KEY` (ou uma credencial OpenAI própria); `google` requer `GEMINI_API_KEY` ou `GOOGLE_GEMINI_API_KEY` (ou uma credencial Google própria).
- Se a chave está salva no banco, mas não pode ser descriptografada, confira se `APP_KEY` permaneceu a mesma desde a gravação. Não substitua a `APP_KEY` sem planejar a recuperação dos dados já criptografados.
- Se a chave é global do EasyPanel, garanta que a variável chegue ao mesmo contêiner/processo PHP que executa o RS Connect.
- **Não** altere `can_operate`, `status` ou `auto_reply_enabled` diretamente no banco para contornar o bloqueio.
- A presença de uma chave não certifica saldo, permissões de modelo, limite, validade perante o provedor ou conectividade de rede. Isso precisa ser confirmado por teste operacional.

Consulta somente leitura, caso seja necessário conferir vínculos (substitua os IDs pelos valores corretos):

```sql
SELECT id, tenant_id, agent_id, provider, status,
       CASE WHEN COALESCE(api_key_encrypted, '') = '' THEN 0 ELSE 1 END AS has_encrypted_key
FROM ai_provider_credentials
WHERE tenant_id = :tenant_id
  AND (agent_id = :agent_id OR agent_id IS NULL)
ORDER BY (agent_id IS NOT NULL) DESC, is_default DESC, id DESC;
```

Nunca execute `SELECT api_key_encrypted` nem compartilhe o conteúdo da chave com suporte.

## Testes

```bash
php tests/Feature/agent-credential-source-readiness-oct2026-smoke.php
php tests/Feature/agent-readiness-phase2-smoke.php
php tests/Feature/agent-business-save-roundtrip-oct2026-smoke.php
php tests/Feature/agent-business-dashboard-v1-smoke.php
```

**Limitação:** testes locais unitários e de regressão. Nenhum banco MySQL real, provedor de IA ou EasyPanel da instalação do cliente foi acessado durante a preparação deste hotfix.
