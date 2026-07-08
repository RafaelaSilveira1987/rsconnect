# RS Connect — ZIP 04: CRM + Refresh Visual

Base SaaS multiempresa em PHP 8.2+, MySQL, HTML, CSS e JavaScript puro.

Este pacote contém o projeto completo atualizado até a Etapa 4. Ele pode ser usado tanto para atualizar o ZIP 03 quanto para uma instalação nova.

## Recursos desta etapa

### CRM

- base de contatos com busca, classificação, tags e notas;
- contatos criados manualmente ou originados das conversas;
- funil comercial em formato Kanban;
- etapas padrão: Novo, Qualificação, Proposta, Negociação, Ganho e Perdido;
- valor, prioridade, responsável e previsão de fechamento por negócio;
- movimentação de negócios entre etapas;
- atualização automática do status ao mover para Ganho ou Perdido;
- notas cronológicas por negócio;
- tarefas, ligações, reuniões e follow-ups;
- prazo, prioridade, responsável e status das atividades;
- indicadores de negócios e atividades;
- isolamento por `tenant_id` em todas as consultas e gravações;
- permissões específicas para contatos, CRM e tarefas.

### Refresh visual

- paleta clara e mais limpa;
- sidebar branca com navegação agrupada;
- cards com menos sombra e menos gradientes;
- formulários e filtros mais compactos;
- dashboard mais leve;
- tela de conversas reorganizada e com melhor responsividade;
- layout responsivo para desktop, tablet e celular;
- cache de CSS e JavaScript versionado com `v=4.0`.

## Atualização a partir do ZIP 03

Leia:

```text
ATUALIZAR-DO-ZIP-03.md
```

Execute uma única vez:

```text
database/migrations/004_crm.sql
```

Não execute novamente `schema.sql`, `seed.sql` ou migrations anteriores em um banco já existente.

## Instalação nova

1. Copie `.env.example` para `.env`.
2. Configure banco, `APP_URL`, `APP_KEY` e Evolution API.
3. Execute `database/schema.sql`.
4. Execute `database/seed.sql`.
5. Acesse a pasta `public` pelo Apache.

## Novos menus

- Contatos
- CRM
- Tarefas

## Novas permissões

- `contacts.view`
- `contacts.manage`
- `crm.view`
- `crm.manage`
- `tasks.view`
- `tasks.manage`

A migration libera essas permissões para `client_admin` e `client_user`. Depois da atualização, saia da conta e entre novamente.

## Funil padrão

A migration cria um funil chamado **Funil comercial** para cada empresa já existente. Novas empresas criadas pelo Super Admin também recebem o funil automaticamente.

## Requisitos

- PHP 8.2 ou superior;
- extensões PDO MySQL, cURL, OpenSSL, mbstring e JSON;
- MySQL 8 ou MariaDB compatível;
- Apache com `mod_rewrite`.

## Segurança

- preserve o arquivo `.env` da instalação atual;
- não envie `.env` para repositórios;
- em produção, mantenha `EVOLUTION_SSL_VERIFY=true`;
- use uma API Key nova caso uma chave tenha sido exposta em testes.
