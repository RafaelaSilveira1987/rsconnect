# Homologação final — RS Connect 36.6.27

## A. Entrega interna no cliente

- [ ] Criar comunicado para uma empresa específica.
- [ ] Entrar no tenant e confirmar que a caixa aparece sem esperar navegação para Notificações.
- [ ] Confirmar que o título e a mensagem da caixa correspondem ao comunicado mais recente não lido.
- [ ] Minimizar a caixa e confirmar que não ocorreu leitura.
- [ ] Abrir pelo botão compacto e confirmar o drawer.
- [ ] Abrir a mensagem e confirmar que ela deixa de ser não lida.
- [ ] Com zero não lidas, confirmar que a caixa/botão desaparecem.

## B. Independência do módulo Notificações

- [ ] Ocultar o menu/módulo Notificações para a empresa ou perfil de teste.
- [ ] Enviar novo comunicado.
- [ ] Confirmar que a caixa da RS continua aparecendo.
- [ ] Confirmar que o usuário não recebe erro 403 ao abrir, ler, confirmar ou responder ao comunicado.

## C. Abertura pelo histórico

- [ ] Criar comunicado e acessar o histórico/sininho da empresa.
- [ ] Clicar em `Ver detalhes`.
- [ ] Confirmar que o drawer abre diretamente no `communication_id` informado.

## D. Modos de interação

### Somente leitura
- [ ] Não exibe formulário de resposta nem botão de confirmação.

### Confirmar leitura
- [ ] Exibe `Confirmar leitura`.
- [ ] Após confirmação, mostra estado confirmado e não duplica a confirmação.

### Permitir resposta
- [ ] Cliente envia resposta.
- [ ] Super Admin recebe alerta operacional.
- [ ] Resposta aparece em `Comunicados > Respostas`.
- [ ] Super Admin responde.
- [ ] Tópico volta a ficar não lido no cliente.

## E. Admin

- [ ] Aba `Novo comunicado` carrega sem quebra visual.
- [ ] Preview muda ao alterar tipo, prioridade, título, mensagem e modo de resposta.
- [ ] Aba `Histórico` mostra destinatários, leitura, pendências e respostas.
- [ ] Aba `Respostas` mostra empresa, assunto, direção e formulário contextual.
- [ ] Novas interfaces não utilizam emojis.

## F. Compatibilidade

- [ ] Sininho tradicional continua funcionando quando o módulo Notificações está habilitado.
- [ ] WhatsApp/e-mail continuam como `pending_configuration` quando solicitados e não configurados.
- [ ] Comunicação expirada não reaparece como pendência flutuante.
- [ ] Usuário de outra empresa não consegue acessar comunicado que não pertence ao seu tenant.

## Resultado esperado

A comunicação institucional passa a funcionar como recurso global da plataforma e não como dependência da configuração visual do módulo Notificações.
