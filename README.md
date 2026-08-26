# RS Connect — v36.20.9

Esta versão conclui o fluxo de exclusão assistida quando a empresa não possui outra conexão para receber os dados vinculados.

## O que foi corrigido

- a conexão substituta deixa de ser a única alternativa quando existem vínculos;
- o usuário pode escolher **Não tenho outra conexão — remover os dados vinculados**;
- a opção destrutiva exige uma confirmação adicional e mantém o botão bloqueado até todas as validações serem concluídas;
- assistentes, contatos e relatórios agendados são preservados e ficam sem conexão vinculada;
- conversas, campanhas, vínculos de canal e eventos técnicos exclusivos da conexão são removidos definitivamente;
- a auditoria registra o modo `local_discard` ou `assisted_discard` e os totais desvinculados/removidos;
- se a conexão removida era a padrão, outra conexão disponível passa a ser padrão automaticamente;
- continuam disponíveis os modos de transferência para outra conexão e exclusão local sem vínculos.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.9.md`.
