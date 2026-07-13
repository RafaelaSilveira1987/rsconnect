# RS Connect — ZIP 18

## Pré-agendamento opcional + Menus por empresa

Este pacote adiciona duas melhorias estruturais:

1. Pré-agendamento opcional para empresas que precisam de aprovação humana antes de confirmar um horário.
2. Configuração de módulos/menus por empresa, permitindo esconder itens do menu e bloquear acesso direto às rotas desativadas.

## Principais recursos

### Pré-agendamento

- Configuração por empresa.
- IA identifica intenção de agenda em mensagens do WhatsApp.
- Cria registro na Agenda como `Pré-agendado`.
- Salva dia/período preferido e horário/período informado.
- Exibe na Agenda com botões:
  - Aprovar
  - Recusar
  - Remarcar
- Dashboard do cliente ganha card clicável “Intenção de agenda”.
- A IA recebe uma regra adicional para não confirmar horário sozinha quando o pré-agendamento estiver ativo.
- Evento n8n opcional: `appointment.pre_scheduled`.

### Menus por empresa

- Nova seção em Configurações da empresa: “Módulos e menus”.
- Permite controlar:
  - se o menu aparece na lateral;
  - se o módulo pode ser acessado por URL direta.
- Super Admin continua com visão completa.
- Cliente comum é bloqueado quando o módulo está desativado.

## Como atualizar

1. Envie os arquivos para o GitHub.
2. Faça redeploy no EasyPanel.
3. Execute no Adminer:

```text
database/migrations/017_pre_scheduling_tenant_menus.sql
```

4. Pressione `Ctrl + F5` no navegador.

## Como ativar pré-agendamento

Acesse como Super Admin:

```text
Empresas → Editar dados → Pré-agendamento
```

Ative:

```text
Usar pré-agendamento
Exigir aprovação humana
IA pode sugerir disponibilidade
```

Para psicologia, clínica e consultoria, recomenda-se deixar desativada a opção “IA pode confirmar sozinha”.

## Como esconder menus

Acesse:

```text
Empresas → Editar dados → Módulos e menus
```

Para cada módulo, controle:

```text
Menu: aparece ou não na lateral
Acesso: permite ou bloqueia a rota
```

## Teste rápido

1. Ative o pré-agendamento na empresa.
2. Envie pelo WhatsApp algo como:

```text
Queria agendar atendimento na terça à tarde.
```

3. Confira se a conversa aparece no card “Intenção de agenda”.
4. Acesse Agenda e veja se foi criado como `Pré-agendado`.
5. Clique em Aprovar, Recusar ou Remarcar.
