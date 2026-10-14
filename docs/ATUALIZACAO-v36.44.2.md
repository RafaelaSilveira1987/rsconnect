# Atualização RS Connect 36.44.2 — Ativação Assistida enxuta

## Objetivo

Reduzir o esforço e a carga visual da implantação sem remover regras existentes. A tela agora apresenta quatro blocos de negócio e somente uma tarefa por vez.

## O que mudou

1. **Sua empresa** — cadastro + privacidade.
2. **Como atender** — atendimento + agenda + limites.
3. **Conectar** — WhatsApp + assistente.
4. **Testar e ativar** — homologação final.

As oito validações internas continuam sendo registradas. Etapas concluídas ficam disponíveis para edição no resumo lateral.

### Configuração expressa

Campos técnicos e opcionais ficam recolhidos. Valores recomendados continuam sendo enviados ao backend, portanto a simplificação é de experiência e não reduz proteções.

### Agenda

A Agenda interna permite escolher no onboarding:

- uma pessoa por horário; ou
- mais de uma pessoa no mesmo horário, informando a capacidade padrão.

Também foi simplificada a decisão de confirmação entre aprovação humana e confirmação automática.

## Banco de dados

Não existe migration nova nesta versão. Permanece obrigatória:

`125_native_financial_lifecycle_notifications.sql`

## Deploy

O hotfix pode ser aplicado sobre 36.44.1. Reinicie/redeploy o container para garantir atualização de cache do CSS.
