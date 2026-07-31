# Atualização RS Connect v36.9.1 — Base histórica e métricas por profissional

## Objetivo

Preparar dados confiáveis para o relatório de equipe, sem misturar:

- profissional preferido do contato;
- responsável pela conversa;
- profissional responsável pelo agendamento.

## O que é registrado

### Conversas

- primeira mensagem recebida no ciclo atual;
- primeira resposta humana e usuário responsável;
- abertura, reabertura, pendência e encerramento;
- atribuição, transferência e liberação de responsável;
- origem da atribuição e usuário que realizou a ação.

### Agenda

- criação do compromisso;
- mudança de status;
- confirmação;
- conclusão;
- cancelamento ou recusa;
- não comparecimento;
- troca de profissional;
- alteração de data ou horário;
- exclusão, mantendo um snapshot no histórico.

## Permissões preparadas

- `reports.team.view_own`: profissional visualiza os próprios indicadores;
- `reports.team.view_all`: administrador visualiza toda a equipe;
- Super Admin continua com acesso global pelo mecanismo padrão.

## Instalação

1. Manter a branch `feature/atendimento-por-profissional`.
2. Aplicar os arquivos do patch na raiz do projeto.
3. No Adminer, usar **Importar** e executar:

```text
database/migrations/067_operational_history_metrics_compat.sql
```

4. Fazer commit, push e novo deploy/rebuild no EasyPanel.
5. Atualizar o navegador com `Ctrl + F5`.

## Observação sobre dados anteriores

A migration recupera, a partir das mensagens reais, a primeira entrada e a primeira resposta humana quando possível. Para atribuições e alterações antigas, cria apenas um snapshot do estado atual; não inventa transferências ou mudanças que não estavam registradas.

A partir da aplicação da migration, triggers do banco registram todos os caminhos: painel, webhook, IA, n8n e manutenção da agenda.

## Diagnóstico

Execute no Adminer:

```text
database/diagnostics/operational_history_metrics_v36.9.1.sql
```

Ele mostra prontidão das tabelas, primeira resposta, atribuições por profissional, agenda por profissional e permissões.
