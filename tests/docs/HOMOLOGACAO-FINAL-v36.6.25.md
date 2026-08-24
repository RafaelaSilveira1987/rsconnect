# Homologação — RS Connect 36.6.25

## Banco
- [ ] Aplicar `058_client_communication_center.sql`.
- [ ] Painel de homologação mostra migration base 058.
- [ ] Check “Central de comunicação in-app” aparece Operando.

## Admin
- [ ] Abrir Operação RS → Comunicados.
- [ ] Conferir indicadores de enviados, não lidos, respostas e incidentes.
- [ ] Criar comunicado de prioridade normal sem resposta.
- [ ] Criar comunicado importante com confirmação de leitura.
- [ ] Criar comunicado com resposta habilitada.
- [ ] Confirmar preview dinâmico de título, mensagem, prioridade e ação.
- [ ] Confirmar que não há emojis na nova interface.

## Cliente
- [ ] Com uma mensagem não lida, caixa flutuante aparece no canto inferior.
- [ ] Minimizar a caixa mantém a mensagem não lida e exibe somente o botão compacto.
- [ ] Abrir a mensagem marca leitura.
- [ ] Drawer mostra lista de mensagens e conteúdo completo.
- [ ] Em modo “confirmação”, botão confirma leitura e registra a confirmação.
- [ ] Em modo “resposta”, cliente consegue responder para a RS.
- [ ] Ao zerar não lidas, caixa/botão flutuante desaparecem.

## Resposta RS → cliente
- [ ] Resposta do cliente aparece em Operação RS → Comunicados.
- [ ] O sino operacional da RS recebe alerta da resposta.
- [ ] Super Admin responde pelo histórico.
- [ ] Cliente recebe novamente indicação de não lida, mesmo que o comunicado original já tenha sido lido.

## Validade
- [ ] Comunicado com `Exibir até` deixa de aparecer no floating inbox após vencer.
- [ ] Histórico do sininho permanece preservado.
