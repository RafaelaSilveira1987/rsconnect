# RS Connect — v36.20.6

Esta versão corrige a exclusão assistida quando o cadastro ainda existe no RS Connect, mas a conexão já foi removida diretamente no serviço externo do WhatsApp.

## Principais melhorias

- verifica a existência real da conexão externa antes de exigir confirmações;
- permite remover somente o cadastro local quando a conexão externa já não existe;
- oculta a opção de exclusão externa quando ela não se aplica;
- trata exclusão externa já realizada como uma operação concluída, não como erro;
- mantém a transferência segura de assistentes, contatos, conversas, campanhas e relatórios;
- registra na auditoria se a conexão foi removida agora ou já estava ausente.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.6.md` e `docs/guias/correcao-exclusao-conexao-ausente.md`.
