# RS Connect — v36.17.1

A v36.17.1 reorganiza diretamente no código a tela **Canais WhatsApp → Nova conexão**. O formulário agora possui etapas mais claras, opções em cards selecionáveis, informações auxiliares, eventos avançados recolhíveis, mensagem de rejeição exibida somente quando necessária e rodapé fixo mais estável em desktop e celular.

Não existe nova migration. A estrutura de banco continua até:

`database/migrations/077_ai_efficiency_foundation.sql`

Validação específica:

`php tests/instance-create-layout-v36171-smoke.php`

Roteiro completo: `INSTRUCOES-v36.17.1.md`.

## Histórico v36.17.0

A v36.17.0 adiciona uma camada central de **eficiência de IA**. Cada assistente pode operar em modo Econômico, Equilibrado ou Qualidade máxima; o RS Connect limita o histórico enviado, seleciona localmente os trechos relevantes da base de conhecimento, controla a saída máxima e registra uma estimativa de tokens de entrada evitados. O modelo atual é preservado por padrão.

Aplique a migration:

`database/migrations/077_ai_efficiency_foundation.sql`

Valide com:

`database/diagnostics/ai_efficiency_v36.17.0.sql`

Teste principal:

`php tests/ai-efficiency-v36170-smoke.php`

Roteiro completo: `INSTRUCOES-v36.17.0.md`.

## Histórico v36.16.3

A v36.16.3 corrige a navegação do consumo oficial da OpenAI. O Super Admin agora possui o item **Consumo OpenAI** no menu **Automação e integrações**, com página própria em `/openai-usage`. A tela **IA e credenciais** também contém um atalho para o relatório.

Não existe nova migration. Faça rebuild/redeploy completo para publicar a nova rota e limpar o cache dos assets.

Validação:

`php tests/openai-usage-menu-v36163-smoke.php`

Roteiro completo: `INSTRUCOES-v36.16.3.md`.

## Histórico v36.16.2

A v36.16.2 adiciona ao **Super Admin → IA e credenciais** a consulta direta do consumo oficial da organização OpenAI: tokens, chamadas, modelos, evolução diária e custos. A integração usa uma Admin API Key exclusiva do backend e não exige nova migration.

Configuração principal:

`OPENAI_ADMIN_API_KEY=sk-admin-...`

Validação:

`php tests/openai-organization-usage-v36162-smoke.php`

Roteiro completo: `INSTRUCOES-v36.16.2.md`.

## Histórico v36.16.1

A v36.16.1 libera o gerenciamento completo das conexões WhatsApp para o usuário **Administrador da empresa (`client_admin`)**, mantendo o Super Admin da RS Connect apenas como suporte. O cliente pode criar a própria instância, gerar QR Code, configurar webhook e filtros, testar, sincronizar, reiniciar, desconectar e excluir conexões da própria empresa.

As credenciais globais da Evolution continuam exclusivas do backend: URL técnica e API Key não são exibidas nem aceitas do navegador do cliente. Todas as ações são isoladas por `tenant_id` e protegidas pelas permissões `instances.view` e `instances.manage`.

Não existe nova migration. Permanece obrigatória:

`database/migrations/076_evolution_instance_management.sql`

Validação:

`php tests/evolution-client-admin-v36161-smoke.php`

Roteiro completo: `INSTRUCOES-v36.16.1.md`.

# RS Connect v36.16.0 — Evolution API dentro do sistema

A v36.16.0 introduziu a criação e a administração nativa das instâncias da Evolution diretamente em **Canais WhatsApp**, sem abrir o Evolution Manager.

Aplique a migration:

`database/migrations/076_evolution_instance_management.sql`

Valide com:

`database/diagnostics/evolution_instance_management_v36.16.0.sql`

Roteiro histórico: `INSTRUCOES-v36.16.0.md`.

# RS Connect v36.15.0 — Painel executivo de relatórios da RS Admin

A v36.15.0 cria uma nova visão geral de relatórios para o Super Admin, com indicadores rápidos, comparações com o período anterior, gráficos de movimento, horários de pico, desempenho da equipe, agenda, uso da IA e exportações rápidas.

Acesse como Super Admin:

`Relatórios → Visão geral`

Não existe migration nova. A última migration obrigatória continua sendo:

`database/migrations/074_conversation_message_attachments.sql`

A implantação e a homologação estão descritas em `INSTRUCOES-v36.15.0.md`.

# RS Connect v36.13.0 — Áudios, imagens e documentos nas conversas

A v36.13.0 adiciona recebimento, visualização, reprodução, download e envio de mídias nas conversas. O primeiro pacote suporta imagens JPEG/PNG/WEBP, documentos PDF e áudios MP3/OGG/OPUS/M4A.

Aplique a migration:

`database/migrations/074_conversation_message_attachments.sql`

Configure um volume persistente em:

`/var/www/html/storage/conversation-attachments`

Variáveis principais:

```dotenv
CONVERSATION_ATTACHMENTS_ENABLED=true
CONVERSATION_ATTACHMENT_MAX_MB=20
CONVERSATION_ATTACHMENTS_PATH=/var/www/html/storage/conversation-attachments
```

Validação:

`database/diagnostics/conversation_attachments_v36.13.0.sql`

Consulte `INSTRUCOES-v36.13.0.md` para o roteiro completo de implantação e homologação.

# RS Connect v36.12.1 — Linguagem clara e diagnóstico simplificado


Esta versão mantém o monitoramento da v36.12.0 e reorganiza a comunicação visual para que clientes e administradores entendam rapidamente a situação, o impacto e a ação recomendada. Informações técnicas continuam disponíveis apenas em áreas expansíveis do Super Admin.

A última migration obrigatória continua sendo:

`database/migrations/073_operational_monitoring_alert_delivery.sql`


Aplique a migration:

`database/migrations/073_operational_monitoring_alert_delivery.sql`

O monitor cobre banco, migrations, Evolution, n8n, OpenAI/IA, webhooks, agenda, pagamentos, backups, espaço em disco, filas de mensagens e rotinas de relatório. Os alertas podem ser exibidos dentro do RS Connect e enviados por WhatsApp ou e-mail quando os transportadores estiverem configurados.

A Central de comunicação também passa a entregar comunicados aos clientes pelos mesmos transportadores externos e registrar, por empresa, enviado, erro ou configuração pendente.

Validação:

`database/diagnostics/operational_monitoring_v36.12.0.sql`

Execução automática recomendada: importar o template **Monitor operacional RS Connect** no n8n, usando `OPERATIONS_MONITOR_TOKEN`, ou agendar `php /var/www/html/bin/operations-monitor.php` a cada 15 minutos.

# RS Connect v36.11.1 — Segurança de sessão, CSRF, login e webhooks

Esta versão conclui a etapa de hardening da Beta 1.1 iniciada pela v36.11.0. Ela reforça sessão, autenticação, CSRF e endpoints públicos sem alterar os fluxos funcionais já homologados.

Aplique a migration:

`database/migrations/072_security_session_webhook_hardening.sql`

Depois, revise as variáveis de segurança no `.env`, valide os tokens das integrações e execute:

`database/diagnostics/security_hardening_v36.11.1.sql`

O modo `SECURITY_WEBHOOK_STRICT=true` deve ser ativado somente depois de confirmar `EVOLUTION_WEBHOOK_TOKEN`, `N8N_CALLBACK_TOKEN` quando utilizado e o segredo dos gateways de pagamento ativos.

# RS Connect v36.11.0 — Isolamento entre empresas

A v36.11.0 adiciona uma barreira central no Router para impedir acesso cruzado entre tenants por UUID, ID numérico escondido ou listas adulteradas. O Super Admin mantém o acesso global e as tentativas bloqueadas são registradas em `security_events`.

Validação: `database/diagnostics/tenant_isolation_v36.11.0.sql`.

# RS Connect v36.10.4 — Datas técnicas em UTC e relatórios no fuso da empresa

- define a sessão PDO do MySQL como `+00:00`;
- adiciona `App\Core\Clock` para escrita UTC e conversão de apresentação;
- grava mensagens manuais, automáticas e recebidas pela Evolution em UTC;
- normaliza uma única vez mensagens e marcos históricos pela migration 071;
- recria os 10 triggers operacionais com `UTC_TIMESTAMP()`;
- converte filtros, série diária e histórico recente do relatório para o fuso da empresa;
- preserva `starts_at`/`ends_at` da agenda como horário local do compromisso;
- adiciona diagnóstico `utc_datetime_contract_v36.10.4.sql`.

# RS Connect v36.10.3 — Sincronização resiliente do status e dos ciclos

Esta correção fecha a divergência identificada na homologação: a conversa podia aparecer como **Encerrada** na interface enquanto `conversation_service_cycles` permanecia com `cycle_status = active`.

Aplique, após as migrations 067, 068 e 069:

`database/migrations/070_conversation_cycle_status_sync_compat.sql`

A versão adiciona duas garantias complementares:

- o backend fecha/reabre o ciclo na mesma transação da ação manual;
- o trigger sincroniza os ciclos para atualizações vindas de webhook, n8n e rotinas externas.

Valide com:

`database/diagnostics/conversation_cycle_status_sync_v36.10.3.sql`

# RS Connect v36.10.2 — Status visual das conversas

- diferencia conversas abertas, pendentes e encerradas por cor;
- atualiza o estado visual pelo polling em tempo real;
- mantém junto a recuperação resiliente da migration 069.

# RS Connect v36.10.1 — Recuperação resiliente dos ciclos de atendimento

Esta correção complementa as migrations 067 e 068. Ela cobre conversas que foram criadas ou receberam mensagens durante uma janela em que os triggers ainda não estavam ativos e, por isso, não possuíam registro em `conversation_service_cycles`.

Importe no Adminer, com `log_bin_trust_function_creators` temporariamente habilitado:

`database/migrations/069_service_cycle_recovery_compat.sql`

A migration é idempotente, recupera os dados reais das mensagens e recria somente o trigger de mensagens com autorrecuperação. Depois, valide com:

`database/diagnostics/service_cycle_recovery_v36.10.1.sql`

# RS Connect v36.10.0 — Relatórios de equipe e profissionais

A versão 36.10.0 transforma a base histórica das migrations 067 e 068 em uma tela operacional de relatórios. Acesse **Relatórios → Equipe e profissionais** para comparar atendimento, primeira resposta, transferências, carteira preferencial e resultados da agenda por usuário.

- exige `067_operational_history_metrics_compat.sql` e depois `068_conversation_service_cycles_compat.sql`;
- a migration 068 preserva cada ciclo de atendimento e sua primeira resposta, inclusive após reaberturas;
- profissionais comuns veem somente os próprios indicadores quando possuem `reports.team.view_own`;
- administradores da empresa veem toda a equipe com `reports.team.view_all`;
- Super Admin escolhe uma empresa por vez;
- filtros e exportações usam UUIDs públicos nas URLs.

Diagnóstico: `database/diagnostics/team_professional_reports_v36.10.0.sql`.

# RS Connect — pacote VPS

Pacote consolidado até o RS Connect 36.6.36 — Governança de mensagens e Evolution em tempo real.

## Última etapa incluída

RS Connect 36.6.36 — identifica o atendente nas mensagens humanas, aplica políticas configuráveis de retenção e atualiza QR/conexão da Evolution por webhook com reconciliação automática.

RS Connect 36.6.35 — adiciona criação guiada de prompt, validação de conflitos operacionais e histórico restaurável por assistente.

RS Connect 36.6.34.2 — adiciona a escolha explícita entre Agenda interna, Agenda inteligente homologada pela RS e operação sem agenda.

RS Connect 36.6.34.1 — corrige o redirecionamento após expiração do formulário de login, sem alterar o teste gratuito nem o onboarding.

RS Connect 36.6.34 — transforma o período de teste em regra efetiva de acesso/cobrança e conduz o primeiro acesso em sete etapas sequenciais, preservando empresas que já operavam.

RS Connect 36.6.32 — corrige a retomada de pedidos de agenda fora do horário, processando modalidade/data/disponibilidade antes da IA geral e bloqueando o writer do Google Calendar como integração conversacional.

RS Connect 36.6.28 — preserva a lógica da Central de comunicação e eleva o acabamento do Admin com formulário em etapas, controles estilizados, seleção de empresas em cards e preview mais fiel ao produto.

RS Connect 36.6.26 — fecha o retorno da disponibilidade pela Fila rápida e impede que um único pushName da Evolution vire nome definitivo do contato.

RS Connect 36.6.24 — exige que o atendimento seja definido como Online ou Presencial antes de consultar disponibilidade, filtra os eventos VAGO pela modalidade escolhida e reinicia a busca quando a modalidade muda.

RS Connect 36.6.21 — impede falso “fora do horário” quando outro agente do canal está disponível, revalida o expediente antes do envio e fecha o caminho legado que podia disparar o Google Calendar a partir de respostas comuns.

RS Connect 36.6.20 — bloqueia a criação de eventos Google a partir de conversas comuns/status, endurece a intenção de agenda e torna o callback do backup resiliente a rotação de token.

RS Connect 36.6.19 — corrige o despacho automático de backup para usar sucesso real como fonte de verdade, repetir tentativas no mesmo dia após erro/timeout e expor o motivo de cada decisão do dispatcher.

RS Connect 36.6.14 — mantém a arquitetura de múltiplos Canais WhatsApp da 36.6.13 e reformula a tela de Planos para uma apresentação comercial clara de canais, agentes, usuários, automações e franquia IA RS.

RS Connect 36.6.12 — separa mensagens, interações entregues, chamadas ao provedor, tokens e franquia RS; mede também credencial própria sem descontar do plano e adiciona telemetria técnica por assistente para o Super Admin.

RS Connect 36.6.11 — contabiliza o uso total de IA inclusive com credencial própria sem consumir franquia RS, explica os cards de uso, move as preferências de notificações para o fim da página e corrige a navegação dos módulos marcados pelo Admin RS (incluindo LGPD).

RS Connect 36.6.10 — valida a fila rápida de 1 minuto, renomeia o template para um arquivo identificável e repara franquias de IA que ficaram sem limite após a conversão do plano.

RS Connect 36.6.9 — restaura o intervalo mínimo real entre respostas automáticas, cria uma fila rápida de reavaliação a cada minuto e evita recarregar/voltar ao topo da tela de Conversas ao enviar mensagem humana.

RS Connect 36.6.8 — transforma o limite comercial em interações automáticas de IA, diferencia custeio RS/cliente e recupera no próximo horário válido as conversas recebidas fora do expediente.

RS Connect 36.6.7 — refina severidades operacionais: desativação manual da IA deixa de ser crítico, tokens opcionais/recomendados ganham contexto e gateway sem transações recentes passa a Sem evidência.

RS Connect 36.6.6 — takeover humano passa a pausar também pré-agendamento/agenda, e Cliente/Paciente atual mantém continuidade sem repetir demanda ou triagem.

RS Connect 36.6.5 — conecta o Painel operacional a playbooks de correção, cria alertas internos do Super Admin com recuperação e adiciona o módulo Comunicados para clientes.

RS Connect 36.6.4 — corrige o transporte dos dados das views para que o Painel operacional carregue a fonte única de saúde, serviços, problemas, rotinas e empresas corretamente.

RS Connect 36.6.3 — Painel operacional confiável por evidência: estados com validade, problemas ativos, saúde dos serviços, rotinas e situação por empresa sem falsos verdes.

RS Connect 36.6.2 — Painel operacional paralelo à Central de operação, com leitura por exceção, ações recomendadas, rotinas essenciais e situação por empresa.

RS Connect 36.6.1 — validação ao vivo da Evolution na fila da IA e workflow de backup sem `$env` no n8n.

RS Connect 36.6.0 — estabilização operacional: backup via bash/SSH, revisão de incidentes sincronizada e fila da IA consciente de instância Evolution desconectada.

RS Connect 36.5.9 — hamburger fixado diretamente ao viewport, Central de operação reorganizada, listas extensas compactadas e diagnóstico da fila por instância Evolution.

RS Connect 36.5.8 — reorganização da Administração RS, módulo n8n agrupado, Central de operação aprimorada e navegação global em páginas longas.

RS Connect 36.5.7 — reforço da identificação de novos contatos, resposta tátil no mobile e ativação segura do cron de cobrança.

RS Connect 36.5.6 — correções encontradas na homologação final: classificação de clientes, takeover humano da IA, reprocessamento, cron de cobrança e mobile.

Checkpoint de homologação: `docs/HOMOLOGACAO-FINAL-v36.6.19.md`.

RS Connect 36.5.5 — alinhamento do diagnóstico Beta com a migration 048 e refinamento visual do formulário de endereço em Minha empresa.

RS Connect 36.5.4 — Equipe e acessos em drawer, com cadastro e edição no padrão de Contatos.

RS Connect 36.5.3 — dados mestres compactos e preenchimento automático de endereço por CEP em Minha empresa.

RS Connect 36.3.0 — rotina de backup com job real, callback idempotente, timeout, arquivo verificado e histórico operacional.

HOTFIX 36.2.5 — validação da demanda encerra o fluxo de agenda sem deixar a IA reutilizar opções antigas.

HOTFIX 36.2.2 — exclusão sincronizada: remove o evento vinculado do Google Agenda antes de apagar o registro local.

ZIP 36.2 — agenda conversacional com alternativas reais, escolha do contato, pré-reserva e aprovação profissional.

HOTFIX 36.1.3 — resposta crítica antes das integrações externas e cooldown por mensagem.

HOTFIX 36.1.2 — Persistência do webhook antes do processamento e fila resiliente.

## Atualização principal

Execute as migrations em ordem. Para atualizar a base mais recente, mantenha as migrations anteriores aplicadas e execute:

```text
database/migrations/043_ai_reprocess_schedule.sql
database/migrations/044_ai_pending_failures_message_link.sql
database/migrations/045_ai_webhook_ingestion_resilience.sql
database/migrations/046_calendar_conversational_slot_selection.sql
database/migrations/047_backup_automation_reliability.sql
database/migrations/048_reporting_metrics_foundation.sql
database/migrations/049_operational_resolution_communications.sql
database/migrations/050_human_takeover_customer_context.sql
database/migrations/051_operational_evidence_status.sql
database/migrations/052_ai_usage_and_after_hours_recovery.sql
database/migrations/053_ai_quota_limit_repair.sql
database/migrations/054_ai_metrics_and_delivery_telemetry.sql
database/migrations/055_multi_whatsapp_agent_routing.sql
database/migrations/056_n8n_agenda_event_contract.sql
database/migrations/057_calendar_modality_before_availability.sql
database/migrations/058_client_communication_center.sql
database/migrations/059_contact_identity_confidence.sql
database/migrations/060_free_trial_guided_first_access.sql
database/migrations/061_onboarding_calendar_modes.sql
database/migrations/062_prompt_studio_and_versions.sql
database/migrations/063_message_governance_evolution_realtime.sql
database/migrations/064_professional_conversation_assignment_compat.sql
database/migrations/065_professional_calendar_profiles_compat.sql
database/migrations/066_contact_schedule_overlap_guard_compat.sql
database/migrations/067_operational_history_metrics_compat.sql
database/migrations/068_conversation_service_cycles_compat.sql
database/migrations/069_service_cycle_recovery_compat.sql
database/migrations/070_conversation_cycle_status_sync_compat.sql
database/migrations/071_utc_datetime_contract_compat.sql
database/migrations/072_security_session_webhook_hardening.sql
```

Consulte `README-RS-CONNECT-36.3.0.md` para instalar e validar a rotina de backup.

Consulte `MAPEAMENTO-RELATORIOS-RS-CONNECT-v36.3.0.md` e a migration `048_reporting_metrics_foundation.sql` para a base agregada dos relatórios executivos.

Consulte `README-HOTFIX-36.2.5.md` para validar a etapa de demanda antes da agenda sem alterar o workflow Eventos VAGO.

Consulte `README-ZIP-36.2.md` para instalar e validar a agenda conversacional.

Consulte `docs/AI-REPROCESSAMENTO-AGENDADO.md` para configurar o horário, o cron ou o workflow n8n.

Para a saúde operacional automática, configure `OPERATIONS_MONITOR_TOKEN` e baixe **Monitor operacional RS Connect** em **n8n → Templates**; o workflow distribuído consulta `/webhooks/operations/checks/run` a cada 15 minutos.

## Módulos principais

- Multiempresa.
- WhatsApp/Evolution.
- Conversas com IA e atendimento humano.
- CRM.
- Agenda.
- n8n por empresa.
- Planos, cobranças e gateways.
- Régua de cobrança.
- Notificações.
- Relatórios e conversas com atualização automática.
- QR Code e status da Evolution atualizados por webhook e reconciliação.
- Assinatura pública do atendente nas mensagens humanas.
- Políticas de retenção completa, reduzida ou efêmera.
- Onboarding e prompt guiado.
- Checklist de implantação RS.
- Fila de atendimento e distribuição por equipe.
- Campanhas e disparos controlados.
## Relatórios automáticos (v36.15.1)

A RS Connect gera relatórios executivos em PDF para a RS Admin e para cada empresa, permite programação diária, semanal ou mensal e envia o arquivo pelo WhatsApp. Os PDFs ficam em armazenamento privado e são entregues somente após validação do usuário e do tenant.

