# RS Connect — Repaginação da rota `/agents` (Painel de Negócios)

Data: 14/10/2026. Base: pacote `rs-connect-configuracao-expressa-ui-completo.zip` (Fases 1–3).

## O que foi entregue

- Para usuários de empresa, a rota `GET /agents` agora apresenta um dashboard com abas **Identidade**, **Regras de atendimento**, **Dúvidas frequentes** e **Canais**.
- Para `super_admin`, a antiga interface de manutenção técnica foi preservada, evitando perda das opções avançadas ou edição inadvertida de empresa sem sessão de tenant.
- Lateral de **prontidão**: consulta o serviço real da Fase 2, apresenta score, checklist com linguagem amigável e correções aprovadas pela API.
- Lateral de **simulação**: consulta o laboratório real, em modo de resposta completa. Simulação *não envia mensagens ao WhatsApp nem cria agendamentos*, mas pode utilizar créditos do provedor.
- Os formulários geram o **JSON da Fase 1**, gravado por seu validador tipado/serviço transacional. Nenhum texto livre gera SQL ou altera regras por meio da IA.

## Arquivos principais

- `app/Views/agents/business.php` — interface de negócios (abas e coluna lateral).
- `public/assets/css/agent-business.css` — responsividade e estilo isolados.
- `public/assets/js/agent-business.js` — estado do formulário, validações, chamadas e feedback.
- `app/Controllers/AgentBusinessSimulationController.php` — simulador autenticado, isolado pelo tenant, limitado e sem ações externas de agenda.
- `app/Controllers/AgentController.php` — roteamento para visualização apropriada conforme o perfil.
- `app/Controllers/ExpressSetupController.php` — reutiliza o mesmo prefill de Fase 1 para wizard e `/agents`.
- `app/Controllers/ExpressOnboardingController.php` — autorizações explícitas tanto para implantação quanto para edição de agentes.
- `routes/web.php` — novos endpoints, mantendo contratos anteriores intactos.
- `app/Views/layouts/app.php` — assets carregados somente na nova tela.
- `tests/Feature/agent-business-dashboard-v1-smoke.php` — checagens de estrutura e segurança.

## Endpoints

- `GET /agents` — requer `agents.view`.
- `POST /api/v1/agents/business-save` — requer `agents.manage`, CSRF e autenticação. Reutiliza `ExpressOnboardingController::apply` e `ExpressOnboardingService::apply` da Fase 1, **sem ativar respostas automáticas**.
- `GET /api/v1/agents/{id}/readiness` — requer `agents.view` e identificador público de agente.
- `POST /api/v1/agents/{id}/autofix` — requer `agents.manage`, CSRF e comando permitido.
- `POST /api/v1/agents/{id}/simulate` — requer `agents.manage`, CSRF e identificador público de agente. JSON `{ "message": "Olá", "history": [], "state": {} }`. Resposta `{ "ok", "response", "safe", "message", "state", "history", "needs_review" }`. Limites: corpo de 32 KiB, mensagem de 1.000 caracteres e até 16 turnos anteriores.

## Garantias de comportamento

1. As abas guardam um único objeto JSON; a gravação não acontece ao trocar de aba nem ao escrever perguntas.
2. **Alterações não salvas desabilitam o simulador e as correções automáticas.** O lado direito sempre lê o banco de dados, nunca inventa que um rascunho já está aplicado.
3. Salvar novamente exige confirmação de que o contrato Express pode substituir regras compartilhadas do tenant (incluindo políticas, campos e etapas de atendimento).
4. Quando não há configuração Express prévia, o segmento exige uma seleção explícita. Evita salvar `general_services` sobre um modelo existente sem aviso.
5. O `auto_fix_action` somente é utilizado quando oferecido pelo diagnóstico; a UI exige confirmação e reconsulta o banco após o comando. Regravações subsequentes não revertem automaticamente a correção.
6. O cálculo `can_operate` é exclusivamente do servidor. O navegador não pode ativar o agente por conta própria.
7. Renderização de respostas e FAQ com `textContent` no JavaScript, e `View::e()`/`JSON_HEX_*` no PHP.
8. A consulta e a correção usam `Auth::tenantId()`; nunca recebem `tenant_id` fornecido pelo navegador.

## Atenção: legado e alcance das regras

A implementação usa a arquitetura real **PHP 8.2 + PDO + JavaScript nativo**; não foi acrescentada uma dependência React/Vue. O contrato da Fase 1 é **por empresa**, e sua operação `updateExistingAgent` atualiza exclusivamente `ai_agents.is_default = 1`, não uma configuração individual para cada assistente. Com múltiplos assistentes, a interface informa explicitamente esse limite. A transformação para perfis individuais exigiria alteração de modelo de dados/serviços — não foi simulada apenas na UI.

A edição de agenda avançada, disponibilidade por faixa e confirmação humana continua na área **Agenda**. Instagram ainda não possui conexão funcional neste painel. Avatar é representado pela inicial do nome; não foi implementado upload (fora do contrato da Fase 1).

**Simulação não é homologação de produção**: ela utiliza a configuração persistida e o laboratório existente. Não constitui prova de que todos os cenários, condições de fora de horário e integrações de calendário funcionarão em produção. Não executa o calendário/WhatsApp reais. Usar validação de cenários e homologação integrada para liberar a empresa.

## Atualização

1. Faça backup do código e banco. Garanta que a Fase 1, Fase 2 e o hotfix de transações estejam instalados.
2. Para o patch, copie a **mesma estrutura de pastas** e substitua somente os arquivos incluídos. Para o pacote completo, siga o processo usual de deploy.
3. Não há nova migração SQL nesta repaginação.
4. Limpe cache do proxy/CDN e atualize a página para carregar `agent-business.css` e `agent-business.js`.
5. Entre com usuário de empresa autorizado a `agents.view` / `agents.manage`, valide as abas, edite uma regra e confira o aviso **Mudanças não salvas**.
6. Salve e recarregue a página, confirme persistência no banco, prontidão e simulação. Valide também o auto-fix e verifique se sua correção não será revertida por um salvamento futuro.
7. Homologue ao menos psicologia, imobiliária/serviços, clínica e empresas com horários múltiplos, vínculo WhatsApp e aprovação humana. Nunca estreie direto em empresa de produção sem backup e teste.

## Testes executados no ambiente de geração

Aprovados: lint dos PHP novos/alterados, `node --check` do script, smoke test da nova tela, Fase 1, Fase 2, wizard anterior, blueprint, laboratório e responsividade antiga. O comando local `php` não possui `ext-mbstring`: parte do teste de contrato Fase 1 foi omitida pelo próprio smoke test. Não houve homologação HTTP autenticada com MySQL e WhatsApp reais nem inspeção visual automatizada concluída.

**Falha herdada, comprovada na base anterior:** `tests/Feature/agent-operational-rules-source-of-truth-v363616-smoke.php` apresenta quatro falhas de regras sobre coleta de demanda tanto no pacote inicial quanto neste. Não foi alterado nesta tarefa. Corrigir esse teste e suas regras antes de afirmar conformidade plena do atendimento.
