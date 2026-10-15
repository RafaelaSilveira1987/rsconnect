# RS Connect — Unificação de configurações, Etapa 1 (15/10/2026)

**Base obrigatória:** `rs-connect-layout-unificado-blueprints-guiados-completo.zip` (15/10/2026).  
**Escopo:** backend PHP 8.2/PDO + ajuste de leitura no painel. **Não há migração para Laravel.**  
**Ambiente:** testes estáticos e unitários locais, sem MySQL no executor de testes.

## Mudanças entregues

1. `BusinessHoursNormalizer`: formato compacto (`days/start/end`), mapa detalhado (`mon:[[start,end]]`) e lista da API passam pelo **mesmo algoritmo**, sem permitir sobreposição, dias desconhecidos ou intervalos invertidos. `AgentOperatingPolicyService` e `AgentReadinessEvaluator` reutilizam esse normalizador.
2. `TenantConfigurationProjectionService`: depois de salvar horários na Ativação Assistida, atualiza somente o espelho `tenant_agent_profiles.config_json.express.routing` (quando há Configuração Expressa anterior). A autoridade operacional continua sendo `ai_agents.business_hours_json`. Não altera horários disponíveis na agenda.
3. `TenantExpressConfigurationWriter` + `ExpressConfigurationReconciler`: elimina exclusões globais na escrita cotidiana da Expressa. Os campos de triagem externos, políticas `assisted.*`, etapas especializadas e overrides específicos por agente são preservados. Apenas `requester_name`, `contact_phone` e `express_*` são editados/removidos por esse formulário. Referências a chaves expressas removidas são limpas das etapas coletoras.
4. Modelo pinado: publicar um Blueprint novo não faz uma empresa migrar automaticamente quando ela salva alterações do dia a dia; o compilador usa a versão previamente aplicada. Trocar de modelo exige o fluxo apropriado de revisão.
5. `TenantEffectiveConfigurationService`: resolução somente-leitura, por domínio, das autoridades operacionais. Sem nova tabela de configuração.
6. `ConfigurationConsistencyAuditService`: detecta divergências de expediente, confirmação automática em empresa que exige aprovação humana, duplicatas por texto, link padrão inválido e versão de modelo contraditória. A API de prontidão inclui `consistency.issues`; conflitos críticos impedem `can_operate` e avisos alteram o score sem autoaplicação.
7. `ExpressBusinessConfigurationReader`: o expediente exibido parte do agente operacional; perguntas de outras telas ficam em `protected_questions`, expostas na tela como somente leitura, fora do payload submetido à API.

## Ownership — regra do produto

- **Admin RS:** catálogo, limites técnicos e versões publicadas dos Blueprints.
- **Empresa / Meu atendimento:** identidade, comercial, perguntas exclusivas Expressa e opções permitidas.
- **Ativação Assistida:** expediente compartilhado e seleção de campos operacionais (consulta as mesmas tabelas).
- **Agenda:** aprovação, capacidade, duração, antecedência, janelas e efetivação das reservas. **Não** são alteradas pela Configuração Expressa.
- **IA/Prompt:** somente apresentação das regras; não é fonte autorizativa nem pode fazer SQL por texto livre.

Precedência no runtime: mecanismos de segurança > regras operacionais de agenda e triagem > preferências válidas da empresa > espelho de UI > prompt. Quando fontes divergem, a auditoria registra a diferença e exige revisão humana, sem impor automaticamente uma escolha.

## Como executar a auditoria, sem gravar

Dentro do container com dependências e `.env` já configurados:

```bash
php scripts/audit-tenant-config.php --tenant=123
```

Substitua `123` pelo ID interno da empresa autorizado ao operador. O script aceita **um tenant obrigatório**, só lê e retorna `agent_id`, prontidão e chaves de pendências (sem segredo, telefone ou conteúdo de conversa). O resultado não deve ser publicado em áreas abertas.

## Plano de atualização

1. Fazer backup verificável do banco e dos arquivos. Não aplicar patch diretamente em produção sem homologação.
2. Conferir se a base instalada coincide com a versão mencionada acima; se não, use o pacote completo **após comparar personalizações locais**.
3. Aplicar o patch preservando as pastas e limpar cache PHP/opcache, se necessário. O JS do painel recebe `rev` atualizado.
4. Executar `php tests/Feature/configuration-unification-20261015-smoke.php` e testes funcionais de regressão.
5. Fazer auditoria CLI de **uma empresa de homologação**.
6. Alterar um horário na Ativação Assistida; abrir Meu atendimento, conferir o mesmo horário; salvar sem trocar de Blueprint, recarregar e comparar os registros.
7. Cadastrar duas perguntas na Ativação Assistida, outras duas em Meu atendimento; salvar pelas duas telas, confirmar que as quatro aparecem onde esperado e seguem válidas na execução, sem duplicatas nem alteração das permissões da agenda.
8. Simular uma confirmação automática indevida com aprovação humana habilitada e confirmar bloqueio pela prontidão.

## Cenários automatizados executados localmente

- Horários compacto/detalhado/lista iguais; casos inválidos bloqueados.
- Perguntas e políticas de outra tela preservadas na reconciliação; uma pergunta Expressa removida não apaga as demais.
- Fluxo coletor reorganizado antes da agenda, sem duplicar campos já coletados.
- Aprovação humana prevalece sobre capability permissiva.
- Auditoria de divergências de horário e conflitos de confirmação.
- Smoke tests da Fase 1, Fase 2, ativação, front e agenda selecionados.

**Não executado:** integração real com MySQL, concorrência entre HTTP workers, WhatsApp, Calendar API, PostgreSQL (não utilizado) ou inspeção automatizada do CSS. Testes legados que fixam versões antigas do manifesto ainda falham por incompatibilidade de versão, e um conjunto antigo de demanda já falhava na base original. Não declarar homologação de produção até executar os testes acima em ambiente real.

## O que deliberadamente NÃO foi alterado

- Os serviços operacionais de consulta, confirmação, cancelamento e reagendamento da Agenda.
- Capacidade por horário, aprovação humana, antecedência, buffers e profissionais.
- A lógica de mensagens enfileiradas fora do expediente e os dados de agendamentos existentes.
- A aplicação retroativa de Blueprints.
- O status ativo/inativo de agentes.

## Próxima etapa antes da Agenda

A unificação está **em implantação progressiva**, não total. `AgentBlueprintService::applyBlueprint`, `AgentBlueprintService::saveTenantConfiguration`, alterações técnicas de agentes e editor de agenda ainda possuem comandos próprios. É necessário convergi-los para comandos por domínio com bloqueio otimista/revisão de diferenças e auditar empresas existentes antes de modificá-los. Só depois fechar a Agenda de ponta a ponta (consulta, reserva/hold, confirmação com aprovação, cancelamento efetivo, reagendamento atômico e idempotência).
