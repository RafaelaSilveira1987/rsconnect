# RS Connect — Evolução de interface e modelos (15/10/2026)

## Base e escopo

Base exata: `rs-connect-ativacao-unificada-blueprints-completo.zip` entregue em 14/10/2026.

Esta atualização é **incremental**, para a instalação que já possui Ativação Assistida unificada, Fases 1/2 e o editor visual inicial de modelos.

Arquitetura preservada: PHP 8.2, roteamento próprio, PDO, JavaScript nativo. **Sem migrations ou alteração de banco.**

## Mudanças

### 1. Padronização de componentes compartilhados

Um stylesheet opt-in (`public/assets/css/rs-business-unified.css`) harmoniza superfícies, cabeçalhos, filtros, inputs, botões, tabelas e responsividade com o visual de “Meu atendimento”. Aplica-se somente às famílias de rotas:

- `/onboarding` (inclui `/onboarding/express`)
- `/agents`
- `/agent-blueprints` (administração)
- `/conversations`
- `/queue`
- `/contacts`
- `/crm`
- `/calendar`
- `/tasks`
- `/reports`

Não modifica estrutura, navegação nem comportamento funcional de calendários, chats, pipeline Kanban ou fluxos de formulário. Não substitui as especializações dessas telas nem padroniza todos os módulos administrativos — prioridade foi dada às interfaces de atendimento e negócio.

O cabeçalho de `/agents` apresenta **Meu atendimento** para empresas, e **Assistentes cadastrados** para administração RS, sem alterar IDs, URLs ou permissões.

### 2. Configuração guiada de Blueprint — Administração RS

Em `/agent-blueprints` > **Configurar modelo**, há uma seção **Adicionar perguntas recomendadas**, com catálogo fechado de cinco opções:

- Telefone de contato;
- Idade de quem será atendido;
- Nome de quem será atendido;
- Modalidade desejada;
- Primeiro atendimento.

As perguntas existentes são exibidas como “Já faz parte deste modelo” e continuam editáveis no painel de perguntas existentes. Desmarcar a caixa não exclui dados: esta mudança é **somente aditiva**. Remoções e transformações de campos especiais continuam na configuração avançada, com revisão técnica.

A compilação é determinística em `VisualBlueprintEditorService::suggestedQuestions()` / `compile()`: rejeita identificadores fora do catálogo, evita duplicação, limita a 40 campos, gera etapas `collect_*` antes das ações, sem habilitar confirmação automática e preservando aprovação humana. Os fluxos com ações personalizadas continuam impedidos de publicação pelo editor simples.

Publicar gera nova versão via transação e verificação de concorrência pré-existentes; **não altera automaticamente a configuração de empresas já implantadas**.

## Atualização recomendada

1. Fazer backup dos arquivos de implantação e um backup verificável do banco MySQL.
2. Aplicar o **patch** na raiz do projeto apenas se a instalação corresponde à base indicada; caso contrário, comparar alterações primeiro.
3. Limpar OPcache/reiniciar PHP, se necessário; fazer atualização forçada do navegador para carregar a nova folha de estilos.
4. Abrir `/agents` em perfil da empresa e conferir nome, FAQ, regras salvas, readiness e simulação.
5. Conferir `/conversations`, `/queue`, `/contacts`, `/crm`, `/calendar`, `/tasks`, `/reports` em desktop e celular — especialmente os filtros, tabelas e campos de busca.
6. Em `/agent-blueprints` (admin RS), criar nova versão de **um modelo de homologação** com uma pergunta recomendada; verificar ordem do workflow, aprovação humana e isolamento das empresas existentes.
7. Verificar o status da conexão, o agendamento e o atendimento fora do expediente sem habilitar agentes de produção durante a homologação.

## Testes automatizados executados

- `tests/Feature/business-design-and-blueprint-suggestions-oct2026-smoke.php` — passou;
- `tests/Feature/activation-unification-and-blueprint-visual-oct2026-smoke.php` — passou;
- `tests/Feature/agent-express-frontend-smoke.php` — passou (subset DTO ignorado por ausência de `mbstring` no CLI);
- `tests/Feature/agent-readiness-phase2-smoke.php` — passou;
- `tests/Feature/express-onboarding-phase1-smoke.php` — passou;
- `tests/Feature/agent-credential-source-readiness-oct2026-smoke.php` — passou;
- `tests/Feature/onboarding-operation-transaction-guard-regression.php` — passou;
- `tests/Feature/agent-blueprint-policy-engine-v11-smoke.php` — passou;
- `tests/Feature/agent-business-dashboard-v1-smoke.php` — passou;
- `tests/Feature/agent-business-save-roundtrip-oct2026-smoke.php` — passou;
- `tests/Feature/assisted-business-configuration-v36444-smoke.php` — passou;
- `tests/Feature/company-onboarding-segment-owned-v36441-smoke.php` — passou;
- `tests/Feature/triage-agenda-two-scenarios-regression-smoke.php` — passou.

O teste legado `ui-consistency-responsive-v36296-smoke.php` apresentou falhas em comparações de marcadores de versões históricas e em uma expectativa antiga relativa a modos de agenda; o teste não foi ajustado para forçar aprovação. **Não há homologação com MySQL real, EasyPanel, WhatsApp nem inspeção visual em navegador real neste ambiente.**
