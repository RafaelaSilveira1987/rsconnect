# RS Connect — Modalidades online/presencial com uma fonte operacional

Data: 15/10/2026. Base: `rs-connect-hotfix-comercial-fonte-unica-completo.zip`.

## Escopo

O novo formulário em **Meu atendimento > Regras de atendimento > Como acontecem os atendimentos?** permite escolher: não se aplica, somente online, somente presencial, ou ambos. Ao habilitar online, informa o meio (Meet/Zoom) e uma informação operacional opcional. Ao habilitar presencial, exige o local e ao menos um dia permitido. O link HTTPS de reunião permanece no campo de reuniões do painel; ele não ativa automaticamente o atendimento online.

**Fonte operacional única:** `tenant_agent_profiles.config_json.conversation_behavior.service_mode` e `.modalities`. O motor de mensagens já lê essa estrutura por `AgentConversationBehaviorService`. A presença de `config_json.express.modalities_managed=true` marca que a edição dessas propriedades pertence ao painel da empresa; não armazena outro valor concorrente. O editor técnico passa a ser somente leitura para esses campos. Configurações de modelos/prompt permanecem preservadas, exceto as propriedades de modalidade explicitamente alteradas.

## Contrato HTTP

`POST /api/agents/express` continua aceitando o contrato antigo, inclusive em instalações com o wizard antigo. Opcionalmente recebe:

```json
"service_modalities": {
  "mode": "both",
  "online_channel": "Google Meet",
  "online_message": "Enviamos o link após a confirmação",
  "presencial_location": "Rua A, unidade Centro",
  "presencial_days": ["mon", "wed"],
  "presencial_message": "Atendimento presencial mediante confirmação"
}
```

Valores de `mode`: `not_applicable`, `online`, `presencial`, `both`. Dias: `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun`. Textos de 180/500/300/500 caracteres; validação de enums, lista, unicidade, meio online obrigatório quando habilitado e local/dias presenciais obrigatórios quando habilitados.

**Campo omitido = preservar a modalidade existente.** Um JSON enviado pela implementação anterior não substitui essa configuração. Ao salvar pelo painel, a API persiste a regra na transação da configuração Expressa. A tela realiza leitura posterior e compara o campo de modalidades antes de indicar sucesso.

## Preservação de regras e segurança

- Não executa DDL nem adiciona tabela/migration.
- Não altera agenda existente, disponibilidade, slots publicados, confirmação humana, capacidade, horários de funcionamento ou reservas.
- A empresa decide expressamente quais modalidades oferece, sem inferência a partir de link ou pergunta de triagem.
- A nova atualização preserva `conversation_behavior` individual da empresa ao reaplicar um Blueprint.
- `AgentBlueprintService::saveTenantConfiguration()` impede que o editor avançado substitua modalidade já gerenciada pela empresa, mesmo que o cliente HTTP envie chaves antigas.
- Para cadastros legados sem o marcador, o editor avançado mantém o comportamento anterior. O painel lê os valores existentes em vez de inventar uma seleção.
- Se a empresa escolher "ambos", deve verificar se a **Ordem do atendimento** possui a coleta de modalidade no momento correto; o novo formulário não cria silenciosamente outra etapa de triagem.

## Instalação

1. Backup do banco e dos arquivos; preferencialmente homologação primeiro.
2. Se o último patch comercial já foi aplicado, copiar os arquivos do patch desta atualização sobre os existentes. Caso a versão instalada seja anterior, aplicar o ZIP completo em ambiente preparado com backup e preservar `.env`, arquivos anexados e volumes persistentes.
3. Limpar cache do frontend/recarregar completamente; referências JS/CSS foram versionadas.
4. Na empresa de teste, abrir `Meu atendimento`, escolher **Online e presencial**, informar o meio, o local e marcar os dias. Salvar e recarregar; verificar leitura igual ao que foi escrito.
5. Abrir o editor avançado do Admin e confirmar que a modalidade aparece em **somente leitura**. Editar outra regra avançada e verificar que a modalidade continua igual ao voltar.
6. Testar um envio pelo wizard antigo, sem `service_modalities`, e conferir que o campo original não se altera.
7. Testar uma empresa legada que ainda configure modalidades no editor avançado; ela não deve perder os valores até migrar expressamente.
8. Testar agenda em homologação: vagas publicadas, modalidades, triagem e confirmação pela equipe. Nada aqui deve habilitar confirmação automática.

## Verificações executadas

Passaram: `service-modalities-single-source-regression.php`, `express-onboarding-phase1-smoke.php`, `express-commercial-single-source-regression.php`, `agent-business-save-roundtrip-oct2026-smoke.php`, `agent-business-dashboard-v1-smoke.php`, `agent-readiness-phase2-smoke.php`, `triage-and-hours-consistency-oct2026-smoke.php`, `calendar-modality-before-availability-smoke.php`, `agent-express-frontend-smoke.php`, `business-design-and-blueprint-suggestions-oct2026-smoke.php`.

Falhas **pré-existentes** reproduzidas na base (não atribuídas ao novo patch): `assisted-business-configuration-v36444-smoke.php` ainda exige a expressão antiga de criação de workflow; testes `calendar-preschedule-confirm-v36413-smoke.php` e `calendar-reschedule-release-v36417-smoke.php` ainda exigem a versão 36.41.8 e ausência de nova migration, diferentes da versão do pacote atual.

O ambiente de teste não possui `ext-pdo_mysql` nem conexão com MySQL real, portanto **não houve homologação transacional com banco, Google Calendar ou WhatsApp**. A interface não foi validada em navegador real com sessão da plataforma.

## Próxima fase (agenda)

A Agenda possui regras adicionais, como slot publicado explicitamente, duração, aprovação humana, capacidade e mudança de horário. As modalidades agora têm fonte única para o assistente, mas **este patch não garante sozinho** que todas as fontes de disponibilidade e todas as operações de agenda respeitem o mesmo contrato. A próxima fase deve unificar confirmação, cancelamento e reagendamento, testar concorrência em MySQL e rever a prioridade de slots publicados versus os dias gerais de modalidade.
