# Homologação final — RS Connect 36.6.28

## Central de comunicação — Admin RS

- [ ] Cabeçalho do módulo mantém leitura clara e indicadores compactos.
- [ ] Abas Novo comunicado / Histórico / Respostas estão estilizadas e navegáveis.
- [ ] Formulário apresenta as etapas 01 Conteúdo, 02 Destino e interação e 03 Entrega.
- [ ] Inputs e selects possuem bordas, altura e foco consistentes.
- [ ] Textarea possui altura e espaçamento adequados.
- [ ] Ajuda contextual dos campos aparece de forma discreta.
- [ ] Destinatários aparecem em cards selecionáveis com scroll interno.
- [ ] Canais aparecem como cards e RS Connect fica visualmente marcado como canal interno ativo.
- [ ] Validade e orientação de expiração permanecem compreensíveis.
- [ ] Barra de envio permanece clara e acessível.
- [ ] Preview lateral acompanha título, mensagem, tipo, prioridade e modo de resposta.
- [ ] Bloco de boas práticas não compete visualmente com o preview.
- [ ] Não há emojis na nova interface.

## Regressão funcional

- [ ] Comunicado interno continua sendo criado normalmente.
- [ ] Empresa destinatária continua recebendo a caixa flutuante.
- [ ] Abrir a mensagem registra leitura.
- [ ] Confirmação continua funcionando para `acknowledge`.
- [ ] Resposta continua funcionando para `reply`.
- [ ] Resposta da RS volta a marcar o tópico como não lido para a empresa.
- [ ] Histórico mantém lidas, pendentes e respostas.

## Banco

- [ ] Nenhuma migration nova foi aplicada por causa da 36.6.28.
- [ ] Migration 058 já existe para a Central de comunicação.
- [ ] Base consolidada permanece em 059.

## Cache

- [ ] `app.css?v=36.6.28` carregado.
- [ ] `app.js?v=36.6.28` carregado.
