# RS Connect — pacote VPS

Pacote consolidado até o RS Connect 36.6.27 — Central de comunicação refinada.

## Última etapa incluída

RS Connect 36.6.27 — torna o inbox institucional independente do menu de Notificações, renderiza a mensagem não lida já no servidor e reorganiza a Central de comunicação do Admin em abas mais claras.

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
- QR Code da Evolution nas instâncias.
- Onboarding e prompt guiado.
- Checklist de implantação RS.
- Fila de atendimento e distribuição por equipe.
- Campanhas e disparos controlados.
