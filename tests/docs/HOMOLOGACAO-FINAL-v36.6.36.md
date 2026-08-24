# Homologação final — RS Connect 36.6.36

## 1. Banco e ambiente

- [ ] Migration 062 aplicada, caso ainda estivesse pendente.
- [ ] Migration 063 aplicada sem erro.
- [ ] `MESSAGE_RETENTION_TOKEN` configurado.
- [ ] `EVOLUTION_WEBHOOK_TOKEN` configurado.
- [ ] `APP_URL` aponta para o domínio HTTPS correto.
- [ ] Tela de homologação mostra migration base 063.

## 2. Assinatura do usuário no WhatsApp

1. Crie ou edite dois usuários da mesma empresa.
2. Defina nomes públicos diferentes, por exemplo `Rafaela` e `Lucas`.
3. Defina funções diferentes, como `Atendimento` e `Suporte`.
4. Na empresa, habilite assinatura humana no formato Nome + função.
5. Entre como Rafaela e envie uma mensagem manual.
6. Entre como Lucas e envie outra mensagem manual.

Esperado:

- [ ] O WhatsApp recebe `Rafaela — Atendimento` antes da primeira mensagem.
- [ ] O WhatsApp recebe `Lucas — Suporte` antes da segunda mensagem.
- [ ] O histórico interno identifica o usuário correto em cada envio.
- [ ] A resposta da IA não recebe assinatura de usuário humano.
- [ ] Desabilitar a assinatura na empresa volta a enviar somente o texto.
- [ ] Desabilitar a assinatura em um usuário impede a assinatura apenas para ele.

## 3. Retenção reduzida

Use uma empresa de teste e uma janela curta somente durante a homologação.

- [ ] Selecione modo Reduzido.
- [ ] Defina o número de dias conforme o cenário de teste.
- [ ] Execute a retenção manualmente.
- [ ] Conteúdo fora da janela é removido.
- [ ] Mensagens recentes permanecem visíveis.
- [ ] Data, direção, status e remetente continuam registrados.
- [ ] O preview de conversa antiga não expõe o texto removido.
- [ ] O histórico informa que o conteúdo foi removido pela política.

Depois, restaure a janela comercial desejada, por exemplo 90 dias.

## 4. Retenção efêmera

- [ ] Selecione modo Efêmero.
- [ ] Configure a janela em horas.
- [ ] Uma conversa ainda ativa não perde conteúdo antes de sair da janela.
- [ ] Uma conversa antiga perde o conteúdo após execução da política.
- [ ] Busca no texto antigo deixa de localizar conteúdo já removido.

## 5. Payload técnico

- [ ] Configure a janela de payloads.
- [ ] Execute a política.
- [ ] `raw_payload_json` antigo é removido.
- [ ] A mensagem continua no histórico como metadado.
- [ ] Uma nova mensagem continua sendo recebida normalmente.

## 6. Automação da retenção

- [ ] Baixe o template atualizado pelo RS Connect.
- [ ] Importe e publique no n8n.
- [ ] Execute o node manualmente.
- [ ] O endpoint retorna `ok: true`.
- [ ] O resumo informa empresas verificadas, conteúdos e payloads removidos.
- [ ] Token inválido retorna HTTP 403.
- [ ] Sem token configurado, o endpoint retorna HTTP 503.

## 7. QR Code e conexão Evolution

1. Abra uma instância desconectada.
2. Solicite o QR Code.
3. Observe a tela sem recarregar.
4. Escaneie o QR.

Esperado:

- [ ] Estado muda para Aguardando leitura do QR Code.
- [ ] QR atualizado aparece no modal.
- [ ] Após leitura, o estado passa por Conectando e chega a Conectado.
- [ ] Modal do QR é fechado após conexão.
- [ ] Formulário de QR fica oculto quando conectado.
- [ ] Nome/telefone do perfil aparecem quando fornecidos pela Evolution.
- [ ] Desconectar a sessão atualiza a tela sem F5.
- [ ] Reconectar gera evento de recuperação.

## 8. Proteção contra webhook perdido

- [ ] Interrompa temporariamente o recebimento do webhook ou simule estado desatualizado.
- [ ] Abra a tela de instâncias.
- [ ] O feed consulta a Evolution quando o status estiver antigo.
- [ ] A tela converge para o estado real sem criar chamadas contínuas excessivas.

## 9. Regressão

- [ ] Mensagens recebidas continuam acionando cooldown e IA conforme configuração.
- [ ] Atendimento humano continua pausando a IA.
- [ ] Busca de conversas e contatos continua funcionando.
- [ ] Agenda interna e Agenda inteligente permanecem separadas.
- [ ] Prompt Studio continua criando e versionando prompts.
- [ ] Onboarding e teste gratuito continuam acessíveis.
- [ ] Central de comunicação interna continua exibindo não lidos.

## Resultado esperado

A versão estará homologada quando a identificação humana for correta, a limpeza respeitar a política escolhida sem apagar metadados essenciais e o status da Evolution acompanhar QR/conexão sem exigir atualização manual da página.
