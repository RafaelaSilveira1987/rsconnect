# Homologação final — RS Connect 36.6.29

## Busca

- [ ] Nome completo encontra a conversa correta.
- [ ] Parte do nome encontra a conversa correta.
- [ ] Telefone somente com números encontra a conversa.
- [ ] Telefone com máscara/espaços/hífen encontra a conversa.
- [ ] E-mail cadastrado encontra a conversa.
- [ ] Empresa cadastrada encontra a conversa.
- [ ] Trecho da última mensagem encontra a conversa.
- [ ] Trecho de mensagem antiga do histórico encontra a conversa.
- [ ] A lista é atualizada enquanto digita sem recarregar a página.
- [ ] Limpar a busca devolve a lista normal.
- [ ] Os demais filtros continuam funcionando em conjunto com a busca.

## Avatar

- [ ] Contato com foto disponível exibe a imagem na lista.
- [ ] A conversa selecionada exibe a mesma foto no cabeçalho.
- [ ] Contato sem foto mantém as iniciais.
- [ ] URL inválida ou imagem que falha não quebra o layout.
- [ ] Mensagem recebida continua funcionando mesmo se a consulta da foto falhar.
- [ ] Evento `contacts.upsert`, quando enviado pela Evolution, atualiza a foto armazenada.

## Regressão

- [ ] Envio humano continua sem recarregar a tela.
- [ ] Scroll da lista e do histórico permanece independente.
- [ ] IA/Humano/Pausado não sofreu alteração.
- [ ] Agenda não sofreu alteração.
- [ ] Comunicação in-app não sofreu alteração.

## Teste automatizado

Executar:

```bash
php tests/conversation-search-avatar-smoke.php
```

Resultado esperado:

`OK - busca de conversas e avatar do contato validados.`
