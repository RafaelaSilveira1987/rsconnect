# Atualizar do ZIP 19.1 para o ZIP 20

## 1. Enviar arquivos

Suba todos os arquivos do ZIP 20 para o repositório GitHub do RS Connect.

## 2. Redeploy

No EasyPanel, faça redeploy do serviço `rsconnect`.

## 3. Migration

No Adminer, selecione o banco `rs_connect` e execute:

```text
database/migrations/020_queue_team_distribution.sql
```

A migration cria:

- tabela `service_departments`;
- tabela `conversation_internal_notes`;
- novas colunas na tabela `conversations`;
- permissões `queue.view` e `queue.manage`;
- setores padrão por empresa.

## 4. Cache

Após o deploy, pressione:

```text
Ctrl + F5
```

## 5. Validação

Acesse:

```text
/queue
```

Depois abra uma conversa e teste:

- definir responsável;
- definir setor;
- alterar prioridade;
- alterar status operacional;
- criar anotação interna.
