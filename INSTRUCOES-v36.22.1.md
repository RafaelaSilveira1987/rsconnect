# RS Connect v36.22.1 — hotfix de recebimento Evolution/PDO

## Problema corrigido

O webhook da Evolution chegava ao RS Connect, identificava a instância e retornava HTTP 500 antes de salvar contatos ou mensagens:

```text
SQLSTATE[HY093]: Invalid parameter number
```

A origem era a reutilização do mesmo placeholder nomeado em uma consulta PDO com `PDO::ATTR_EMULATE_PREPARES = false`.

## Correções

- separa os parâmetros `candidate_observed` e `candidate_promoted` ao validar nomes de contatos do WhatsApp;
- corrige dois outros pontos com placeholders repetidos em atualização de conexão e criação de assinatura comercial;
- adiciona teste preventivo para consultas PDO estáticas com placeholders nomeados reutilizados;
- não adiciona migration e não altera o banco.

## Publicação

Substitua os arquivos e reinicie/reimplante o serviço RS Connect. Não é necessário executar migration.

Depois envie uma mensagem por outro WhatsApp. O resultado esperado é:

```text
Evolution -> HTTP 200 no webhook -> contato criado -> conversa criada -> IA responde
```

Para acompanhar o teste:

```bash
tail -f storage/logs/evolution-webhook.log
```
