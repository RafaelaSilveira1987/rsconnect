# RS Connect 36.28.5 — Diagnóstico com correção automática segura

## Objetivo

Evitar que a tela de Saúde e diagnóstico apenas aponte um problema conhecido e obrigue o operador a procurar código ou SQL para limpar o estado.

## Auto-reparo

A camada `TenantSelfHealingService` centraliza correções operacionais seguras e idempotentes. Ela pode ser executada pelo diagnóstico manual, pelo cron de saúde e pelo botão **Corrigir agora**.

Ela não inventa regras de negócio, credenciais, URLs ou horários. Quando o problema depende de configuração humana, a tela continua direcionando para **Abrir configuração**.

## Agenda

O diagnóstico agora respeita a origem realmente selecionada pela empresa:

- Agenda interna do RS Connect: não exige n8n ou Google;
- Google Agenda: valida integração, sincronização e eventos;
- Sem agenda: informa que a agenda automática não está em uso.

A manutenção também passou a liberar pré-reservas vencidas que ficaram presas apenas em `calendar_availability_slots`. Antes, o diagnóstico contava esses registros, mas a manutenção tratava somente holds vinculados ao ciclo Google no compromisso; por isso o alerta podia permanecer para sempre.

## Operação

Quando houver uma pendência que pode ser corrigida com segurança, a tela mostra **Corrigir agora**. O botão executa o reparo, atualiza o diagnóstico e registra auditoria.

Não há migration nova nesta versão. A migration mínima continua sendo `104_customer_patient_continuity_guard.sql`.
