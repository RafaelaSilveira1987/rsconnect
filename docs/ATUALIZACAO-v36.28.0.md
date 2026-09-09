# RS Connect 36.28.0 — Blueprints por nicho e Policy Engine

## Objetivo

Esta versão separa linguagem de IA, fluxo de atendimento e regras críticas. O LLM continua responsável por interpretar linguagem natural e conversar, mas ações como consultar agenda, pré-agendar ou confirmar passam por regras determinísticas do backend.

Princípio central:

> **A IA propõe/interpreta; o RS Connect autoriza e executa.**

## Arquitetura

A configuração agora possui quatro camadas:

1. **RS Connect / regras sistêmicas** — ações críticas sempre dependem de estado persistido e autorização do backend.
2. **Nicho + Blueprint versionado** — configuração inicial por segmento.
3. **Configuração da empresa** — cópia do blueprint, que pode ser personalizada sem alterar o template global.
4. **Prompt Studio** — identidade, tom, vocabulário e forma de perguntar; não é fonte de autorização de negócio.

### Estruturas principais

- `business_niches`
- `agent_blueprints`
- `agent_blueprint_versions`
- `tenant_agent_profiles`
- `tenant_agent_capabilities`
- `tenant_triage_fields`
- `tenant_agent_policies`
- `tenant_agent_workflow_steps`
- `conversation_triage_sessions`
- `conversation_policy_decisions`

## Blueprints iniciais

### Psicologia

Padrão seguro:

- triagem e elegibilidade habilitadas;
- idade mínima: 14 anos;
- idade obrigatória antes da agenda;
- identifica se o atendimento é para o próprio contato ou terceiro;
- modalidade obrigatória antes da agenda;
- consulta e pré-agendamento permitidos somente após elegibilidade;
- confirmação automática desabilitada;
- aprovação humana obrigatória;
- decisões críticas registradas em auditoria.

### Barbearia / Salão

Padrão orientado a serviço:

- nome do cliente;
- serviço desejado;
- profissional opcional;
- preferência de dia/horário;
- sem regra clínica de idade;
- confirmação automática pode ser habilitada, sempre dependente de slot real.

Também são criados blueprints iniciais para Clínica/Consultório e Serviços em geral.

## RS Admin

### Nichos e blueprints

Nova tela de Super Admin:

`/agent-blueprints`

Permite:

- criar/editar nichos;
- criar/editar blueprints;
- publicar novas versões de um blueprint;
- editar o JSON estrutural da versão;
- manter orientação complementar ao Prompt Studio.

Uma nova versão **não altera empresas existentes automaticamente**. Tenants possuem sua própria cópia da configuração.

### Cadastro da empresa

No cadastro de uma empresa, o Super Admin pode selecionar:

- nicho;
- blueprint inicial.

### Configuração por empresa

Na configuração da empresa há o bloco **Arquitetura do agente — Blueprint, triagem e travas por nicho**, no qual é possível:

- aplicar/trocar blueprint;
- escolher interação `Híbrido`, `Formulário` ou `Prompt`;
- habilitar/desabilitar capabilities;
- configurar campos de triagem;
- definir quais campos são obrigatórios antes da agenda;
- editar políticas, valores, ações e mensagens ao cliente;
- visualizar o workflow aplicado.

## Modos de interação

### Híbrido — recomendado

O backend conduz deterministicamente os campos obrigatórios e o Prompt Studio controla linguagem quando apropriado. É o padrão mais seguro.

### Formulário

Perguntas e mensagens configuradas são enviadas de forma determinística.

### Prompt

O Prompt Studio pode formular a pergunta, mas o backend ainda impede agenda/ações enquanto faltar informação obrigatória ou existir política de bloqueio.

## Caso crítico: menor de idade em psicologia

Mensagem de exemplo:

> Quero marcar psicólogo para minha filha. Ela vai fazer 8 anos agora dia 18 de setembro.

A triagem extrai:

- atendimento para terceiro;
- relacionamento familiar quando detectável;
- idade `8`;
- intenção de agendamento.

O Policy Engine compara com `minimum_age = 14` e retorna bloqueio antes de qualquer consulta de agenda.

Resultado esperado:

- `conversation_triage_sessions.status = blocked`;
- `eligibility_status = blocked`;
- `block_reason = minimum_age`;
- nenhuma consulta de agenda para o pedido;
- nenhum slot criado;
- nenhum pré-agendamento criado;
- nenhuma confirmação criada;
- resposta ao cliente vem da política configurada;
- o LLM não pode substituir essa decisão.

## Travas de agenda

O `PreSchedulingService` possui defesa em profundidade: mesmo que outro caminho tente acessar a agenda diretamente, o `AgentTriageService::schedulingGate()` volta a avaliar o perfil e o Policy Engine.

A confirmação automática também passa por `calendar.confirm`. Portanto, o texto da IA não é suficiente para transformar uma preferência em agendamento.

## Prompt Studio

O prompt do cliente continua válido para:

- nome/persona do assistente;
- tom;
- tamanho das mensagens;
- palavras a evitar;
- naturalidade;
- como acolher e formular perguntas;
- informações institucionais.

Recomenda-se remover gradativamente do prompt regras críticas que agora existem no Policy Engine, evitando duplicidade e conflito de instruções.

## Deploy

1. Fazer backup do banco e do projeto atual.
2. Subir os arquivos da versão.
3. Executar:

```bash
php bin/migrate.php up
```

4. Confirmar migrations:

```bash
php bin/migrate.php status
php bin/migrate.php verify
```

5. Reiniciar PHP-FPM/container para limpar OPcache.

## Configuração da cliente de psicologia atual

Após o deploy, no RS Admin:

1. Abrir **Empresas → Configurações da empresa**.
2. Localizar **Arquitetura do agente — Blueprint, triagem e travas por nicho**.
3. Selecionar **Psicologia — Triagem e aprovação humana**.
4. Usar preferencialmente o modo **Híbrido**.
5. Confirmar:
   - `patient_age` obrigatório antes da agenda;
   - `requester_name` obrigatório antes da agenda;
   - `is_for_self` obrigatório antes da agenda;
   - `modality` obrigatório antes da agenda;
   - política `minimum_age = 14` ativa;
   - `calendar.confirm` desabilitado;
   - `calendar.human_approval` habilitado;
   - `confirmation_requires_human = true`.

Se o `segment` existente da empresa já indicar claramente psicologia/psicoterapia, o serviço tenta associar o blueprint compatível automaticamente. Mesmo assim, a conferência manual é recomendada na homologação.

## Homologação obrigatória

### Cenário 1 — menor bloqueado

Enviar:

> Quero marcar psicólogo para minha filha. Ela vai fazer 8 anos.

Esperado:

- mensagem configurada para menor;
- agenda não consultada;
- nenhum pré-agendamento;
- nenhum compromisso.

### Cenário 2 — dados incompletos

Enviar:

> Quero marcar uma consulta.

Esperado:

- coleta de um campo obrigatório por vez;
- agenda bloqueada até completar os requisitos definidos pela empresa.

### Cenário 3 — adulto elegível

Completar nome, titularidade, idade >= 14 e modalidade.

Esperado:

- somente depois da elegibilidade a agenda pode ser consultada;
- em Psicologia, disponibilidade pode gerar pré-agendamento, mas confirmação depende da configuração de aprovação humana.

### Cenário 4 — barbearia/salão

Aplicar o blueprint de beleza em um tenant de teste.

Esperado:

- não perguntar idade clínica;
- coletar serviço e preferência;
- usar agenda de acordo com as capabilities daquele tenant.

## Auditoria

Cada decisão relevante pode ser consultada em `conversation_policy_decisions`, incluindo:

- política/capability avaliada;
- ação tentada;
- decisão (`allow`, `block`, `collect`, `handoff` etc.);
- motivo;
- evidências estruturadas utilizadas na decisão.

Isso permite distinguir erro de interpretação de texto, configuração incorreta e bloqueio legítimo de política.
