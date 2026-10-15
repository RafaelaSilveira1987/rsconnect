# RS Connect — Ativação Assistida e gestão de modelos por segmento

## Atualização

Base: `rs-connect-hotfix-readiness-credencial-completo.zip` (14/10/2026).
Este patch não exige alteração de banco, não altera as configurações dos tenants, não cria agentes e não habilita respostas automáticas.

### 1. Navegação

- O menu lateral apresenta somente **Ativação Assistida**.
- A tela `/onboarding` apresenta o cartão **Configurar meu atendimento** com link para `/onboarding/express`.
- A URL `/onboarding/express` continua válida para links antigos, mas não gera um menu redundante.
- A navegação mantém o item principal selecionado enquanto a configuração rápida está aberta.

### 2. Identidade visual

- Arquivo novo: `public/assets/css/rs-activation-unified.css`.
- O estilo é aplicado somente a **Ativação Assistida**, **Configuração Expressa**, **Meu atendimento** e **Modelos por segmento**.
- O padrão compartilha superfícies brancas, raios e bordas, cores, estados de interação e espaços com os módulos atuais.
- Não há alteração global indiscriminada de `.card`, `body` ou CSS de páginas legadas. Outras telas da plataforma continuam exigindo uma etapa própria de unificação visual.

### 3. Quem configura os modelos (blueprints)

- **Administração RS**: `/agent-blueprints` → **Modelos por segmento** → **Configurar modelo**.
- **Empresa**: `/onboarding` escolhe segmento e modelo; `/onboarding/express` ajusta as necessidades da empresa; `/agents` edita as regras próprias dela.
- Alterações no modelo produzem **nova versão** da tabela `agent_blueprint_versions`. Empresas já vinculadas ao modelo não recebem a nova versão automaticamente.

O editor visual permite alterar:

1. Ritmo da conversa (natural ou perguntas guiadas).
2. Solicitação de agendamento; neste editor a confirmação automática fica **sempre desabilitada**, e a aprovação humana é exigida.
3. Redação das perguntas existentes e quais são obrigatórias antes da agenda.

A edição avançada do JSON permanece disponível apenas para administração, para segmentos complexos e criação de regras, etapas ou perguntas estruturais que não fazem parte do editor simples. Fluxos com ações personalizadas são rejeitados no editor visual, em vez de sobrescritos.

Ao publicar, o controller bloqueia o registro do modelo dentro da transação, verifica a versão-base para evitar sobrescrita concorrente, valida a configuração compilada e grava uma nova versão. O compilador `VisualBlueprintEditorService` não acessa o banco.

## Instalação

1. Fazer backup do ZIP instalado e da base MySQL.
2. Aplicar o patch preservando a mesma estrutura de diretórios. Ele é **incremental** sobre o hotfix de credenciais entregue imediatamente antes.
3. Limpar cache de PHP/opcache do container, se necessário, e recarregar a tela com atualização forçada.
4. Testar no perfil de empresa: o link está dentro da Ativação Assistida e não no menu.
5. Testar no perfil de administração: editar um blueprint em **Configurar modelo**, publicar versão e conferir histórico.
6. Conferir uma empresa que já usa o modelo: sua configuração deve permanecer idêntica após publicar.
7. Validar responsividade no navegador real (desktop, tablet e mobile).

## Limitações e homologação

- Testes de sintaxe e smoke de fluxo, Fase 1, prontidão, menu e editor visual executados.
- Não foi executado teste integrado com MySQL, Redis, EasyPanel, WhatsApp, nem renderização real automatizada em navegador.
- A compilação visual **não configura horários do cliente**, capacidade de agenda, critérios especiais, nem remove regras customizadas do banco. Esses dados continuam nas telas de cada empresa.
- A estrutura da plataforma é PHP 8.2/ PDO; não requer React/Vue nem Laravel.
