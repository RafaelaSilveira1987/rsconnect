# RS Connect — Valor único no atendimento e diagnóstico de modalidades (15/10/2026)

**Base exata:** `rs-connect-hotfix-triagem-expediente-prontidao-completo.zip`.

## Problema reproduzido no código

A tela **Meu atendimento** grava preços e meios de pagamento em `tenant_agent_profiles.config_json.express.commercial`; a edição avançada **Regras do atendimento** usava outro bloco, `conversation_behavior.payment`. A IA e o editor avançado podiam ler um valor antigo ou exibir todos os controles em branco mesmo com preço Expressa válido.

A definição **online/presencial** em `conversation_behavior.service_mode` e `conversation_behavior.modalities` é uma configuração diferente do campo de triagem **Modalidade** e do link de reunião padrão. Nenhum desses dois últimos implica, por si, que o negócio oferece determinada forma de atendimento. Este pacote NÃO marca modalidades automaticamente nem infere dias presenciais; isso poderia criar horários errados.

## Correções entregues

1. `AgentConversationBehaviorService::paymentFromExpressCommercial()` projeta as regras comerciais válidas da Configuração Expressa, de maneira determinística, para o atendimento: preço fixo no formato aceito e formas de pagamento autorizadas. Valores variáveis ou sob consulta não são tratados como números fixos. Strings antigas de `conversation_behavior.payment.message` não são anexadas, pois podem mencionar preços divergentes.
2. `settingsFromProfile()` usa a projeção do Express quando disponível; perfis sem Express continuam com o comportamento anterior.
3. Editor técnico de Regras: apresenta resumo **somente leitura** dos preços canônicos quando Express está salvo. Mantém o editor legado para empresas ainda não migradas.
4. `AgentBlueprintService::saveTenantConfiguration()` preserva a configuração de pagamento legada armazenada ao salvar outras regras avançadas e não permite edição concorrente quando existir preço canônico Express.
5. A descrição de modalidades esclarece que o link do Meet e uma pergunta de triagem não ligam automaticamente o atendimento online nem presencial.

## Proteções

- Não faz alterações automáticas no banco. Não adiciona migrations, não altera calendário, capacidade, confirmação nem aprovação humana.
- Mantém verificações de disponibilidade e regras de coleta já existentes.
- Não promove preços inválidos a regras de resposta. Se houver conflito em dados já persistidos, a projeção evita divulgar o preço legado em `conversation_behavior.payment` durante a conversa. **Não substitui uma auditoria em outras fontes de texto livre ou em mensagens históricas**.
- Se a configuração Expressa não existir, mantém compatibilidade com o editor técnico antigo.

## Verificação recomendada em homologação

1. Backup de código e MySQL e aplicar este patch **somente** sobre a base indicada.
2. Limpar OPcache PHP. Em **Meu atendimento**, verificar o valor e os meios de pagamento; não editar modalidade com base no link da reunião.
3. Abrir a edição técnica e confirmar que mostra **Valores controlados por Meu atendimento**, com o mesmo valor e as mesmas formas de pagamento.
4. Salvar **outra** regra técnica, voltar ao painel comercial e conferir que preço e métodos não foram alterados.
5. No laboratório isolado, perguntar “Quanto custa?” e “Aceitam Pix/cartão?”; confirmar que a resposta não menciona um preço anterior.
6. Se a empresa atende online ou presencial, configurar a **forma de atendimento** explicitamente na área correspondente, incluindo local e dias permitidos; não assumir que a escolha da pergunta Modalidade na triagem fez isso.
7. Homologar com MySQL e provedores reais antes da produção.

## Testes locais

- PHP `-l` nos arquivos alterados.
- `tests/Feature/express-commercial-single-source-regression.php` com assertions habilitadas.
- `agent-business-dashboard-v1-smoke.php`, `agent-business-save-roundtrip-oct2026-smoke.php`, `configuration-unification-20261015-smoke.php`, `agent-readiness-phase2-smoke.php`, `triage-and-hours-consistency-oct2026-smoke.php` e `express-onboarding-phase1-smoke.php`.

**Limite:** nenhuma homologação com banco de produção, WhatsApp, Google Calendar ou navegadores externos foi realizada.
