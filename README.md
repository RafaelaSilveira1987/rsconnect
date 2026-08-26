<<<<<<< HEAD
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
=======
# RS Connect — v36.20.8

Esta versão corrige o botão da exclusão assistida que podia permanecer bloqueado em **Verificando a conexão externa...** enquanto o polling de status consultava a Evolution.

## O que foi corrigido

- o endpoint de status em tempo real libera a sessão PHP antes de consultar a Evolution;
- a prévia de exclusão também libera a sessão antes da chamada externa;
- o polling de status pausa enquanto a gaveta de exclusão estiver aberta;
- a prévia usa timeout de 20 segundos e mostra uma mensagem clara em vez de ficar indefinidamente em verificação;
- respostas redirecionadas para login ou fora do formato JSON são identificadas;
- erros `connectionState HTTP 404/400` passam a ser reconhecidos como conexão externa ausente;
- quando a Evolution não possui mais a instância, o fluxo muda para **Transferir dados e excluir cadastro** ou **Excluir cadastro do RS Connect**.
>>>>>>> 03b6cbd (Cooreção evolution II)

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

<<<<<<< HEAD
Consulte `INSTRUCOES-v36.20.7.md` e `docs/guias/correcao-exclusao-conexao-ausente.md`.
=======
Consulte `INSTRUCOES-v36.20.8.md`.
>>>>>>> 03b6cbd (Cooreção evolution II)
