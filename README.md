# RS Connect — v36.20.7

Esta versão ajusta a exclusão assistida quando a conexão já foi removida da Evolution, mas o cadastro e seus dados ainda permanecem no RS Connect.

## O que foi corrigido

- o modal passa para o modo **exclusão somente local** quando a conexão externa não existe;
- o título, as instruções, a confirmação e o botão deixam claro que somente o cadastro do RS Connect será apagado;
- quando houver assistentes, contatos, conversas, campanhas ou relatórios, a tela continua exigindo uma conexão substituta;
- quando não houver vínculos operacionais, a etapa de destino é ocultada;
- a opção de excluir na Evolution permanece oculta quando a conexão já não existe;
- a auditoria registra se a operação foi somente local ou local com transferência;
- as mensagens finais diferenciam exclusão local, transferência e exclusão externa.

## Comportamento esperado

### Conexão externa ausente e sem vínculos

O botão exibido será **Excluir cadastro do RS Connect**.

### Conexão externa ausente e com vínculos

O botão exibido será **Transferir dados e excluir cadastro**. A conexão substituta continua obrigatória para preservar os dados.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.7.md` e `docs/guias/correcao-exclusao-conexao-ausente.md`.
