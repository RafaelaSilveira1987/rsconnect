# RS Connect — Experiência de Configuração Expressa e Prontidão

## Compatibilidade

Este pacote é baseado na versão do RS Connect que contém as Fases 1 e 2. A aplicação analisada utiliza **PHP 8.2+ com PDO, Views PHP e JavaScript nativo**, e não possui React, Vue ou Tailwind. Por isso, a implementação utiliza componentes de apresentação JavaScript (`AgentWizard` e `ReadinessDashboard`), mantendo o CSS isolado sob o prefixo `rex-` e sem adicionar framework ou pipeline de build.

## Interface

Entre em **Ativação Assistida → Abrir configuração rápida**, ou use **Preparar atendimento** no menu lateral. A rota é `GET /onboarding/express` com autenticação e `onboarding.manage`.

1. Sua empresa: nome, segmento (cinco segmentos suportados pelo Enum ExpressSegment) e estilo de conversa.
2. Atendimento: nome/telefone, perguntas adicionais, preços, meios de pagamento, reagendamento e encaminhamento à equipe.
3. Horários e respostas: fuso, faixas de funcionamento por dia, ação fora do expediente, FAQ e link de reunião.
4. Conferência: resumo de negócio, **Aplicar configuração**, e somente após sucesso consulta do diagnóstico por agente. Há seleção de assistente se a empresa possuir mais de um.

Os dados do backend existentes são carregados da coluna `tenant_agent_profiles.config_json`, do bloco `express` e dos campos estruturados, sem expor system prompts. A página não executa consultas SQL no navegador. Inputs e dados JSON são escapados do lado servidor.

## Contratos utilizados

- `POST /api/agents/express` — os **seis** blocos da Fase 1 são enviados com `Content-Type: application/json` e `X-CSRF-TOKEN`, sempre com `credentials: same-origin`. O tenant é resolvido pela sessão no backend. A rota possui a permissão `onboarding.manage`.
- `GET /api/v1/agents/{uuid}/readiness` — utiliza o `PublicId` do agente e o permissionamento `agents.view`. O score **não** serve sozinho para autorizar atendimento; `can_operate` vem do servidor.
- `POST /api/v1/agents/{uuid}/autofix` — body `{"auto_fix_action":"..."}`, CSRF e permissão `agents.manage`. O botão só aparece se a auditoria o oferecer. Após sucesso, o frontend realiza um novo GET e atualiza o rascunho, evitando desfazer a correção no próximo salvamento.

**Não foi adicionado endpoint de ativação**; o dashboard remete à implantação. Se ainda não existe um `ai_agent`, o wizard salva a configuração da empresa, informa a pendência e oferece navegação para criar assistente/conectar WhatsApp. A criação de agentes permanece no fluxo existente da plataforma.

## Limites de compatibilidade importantes

- O item “A partir de / valor variável” mapeia para `variable_price`, **sem preço mínimo**. O contrato da Fase 1 só aceita `price_value` quando a estratégia é `fixed_price`; para informar “a partir de R$ X”, deverá ser estendido o backend e a validação. O wizard avisa o usuário disso.
- Salvar novamente executa o serviço da Fase 1 e **substitui perguntas, políticas e workflow estruturados do tenant**. A interface exige confirmação sobre a substituição de regras em perfis já salvos. Faça backup antes de atualizar empresas que usam ajustes avançados.
- A configuração Expressa **não ativa o atendimento**. Agentes já ativos podem ter sua configuração modificada por este fluxo (o POST atualiza o agente padrão). Para implantação, teste primeiro em uma empresa de homologação.
- As capacidades `calendar.human_approval`, SLA, políticas específicas do nicho e a configuração de capacidade de agenda continuam sujeitos às telas existentes. A verificação final do Readiness Score usa as entidades reais e pode indicar ajustes adicionais.
- Auto-fix do link de reunião **desabilita** envio automático, não cria um link inexistente; usuário deve confirmar essa decisão.
- Nem todos os usuários com `onboarding.manage` necessariamente têm `agents.view` e `agents.manage`. O frontend apresenta avisos de permissão sem contornar o RBAC.
- No estado atual, apenas agentes existentes são auditados; não se deve fabricar um score quando não há agente.

## Arquivos

- `app/Controllers/ExpressSetupController.php` — leitura e prefill, tenant isolado.
- `app/Views/agent_express/index.php` — markup e dados iniciais.
- `public/assets/js/agent-express.js` — `AgentWizard`, `ReadinessDashboard`, estado, validações e rede.
- `public/assets/css/agent-express.css` — visual responsivo, CSS isolado.
- `routes/web.php` — rota GET e reforço de permissão no POST Express.
- `app/Views/layouts/app.php` — item de menu e assets condicionais.
- `app/Views/onboarding/index.php` — atalho para o wizard.
- `tests/Feature/agent-express-frontend-smoke.php` — smoke test estrutural.

## Homologação obrigatória

1. Empresa nova: preencha os três passos; confirme que `POST /api/agents/express` responde 200, mas não ativa nenhum agente.
2. Empresa com assistente configurado: abrir novamente e confirmar prefill de valores, FAQ, perguntas e todos os intervalos (incluindo dias com duas faixas).
3. Configuração avançada existente: confirmar o aviso antes de sobrescrever perguntas e policies.
4. Testar validação de preço, perguntas repetidas, sobreposição de horários, FAQ incompleto e link de reunião sem HTTPS.
5. Sem `ai_agent`: painel deve orientar o vínculo, não simular score. Com agente, checar a auditoria e a seleção por UUID.
6. Falha crítica: score alto não pode liberar operação. Advertência sem falha crítica não muda `can_operate` enviado pelo servidor.
7. Auto-fix: somente comandos presentes no checklist, confirmação explícita, reconsulta ao backend e refletir o estado corrigido se o usuário editar novamente.
8. Usar perfil sem permissões `agents.manage` e `agents.view`; verificar erro tratado, ausência de auto-fix.
9. Testar em navegador real desktop/mobile, com MySQL, WhatsApp e calendário de homologação; **não realizados neste ambiente**.

Sem migrations novas e sem necessidade de instalar NPM.
