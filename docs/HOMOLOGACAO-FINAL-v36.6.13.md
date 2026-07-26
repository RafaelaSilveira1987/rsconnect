# Homologação — RS Connect 36.6.13

## Pré-requisitos

- [ ] Pacote 36.6.13 implantado.
- [ ] Migration `055_multi_whatsapp_agent_routing.sql` aplicada.
- [ ] Evolution conectada em pelo menos um número de teste.
- [ ] Pelo menos dois agentes ativos disponíveis para o tenant.

## 1. Tela única de canais

- [ ] Menu do cliente mostra **Canais WhatsApp**.
- [ ] Todos os números da empresa aparecem na mesma página.
- [ ] O topo mostra uso `canais utilizados / limite do plano`.
- [ ] Cada card mostra situação, agente principal, quantidade de agentes e conversas.
- [ ] `Ver conversas` filtra pelo número selecionado.

## 2. Vários agentes em um WhatsApp

- [ ] Vincular dois ou mais agentes ao mesmo canal.
- [ ] Marcar exatamente um como principal.
- [ ] Salvar e recarregar.
- [ ] Os vínculos permanecem.
- [ ] O principal aparece identificado no card.

## 3. Roteamento por assunto

Configuração sugerida:

- Recepção — principal.
- Agendamento — especialista — `agendar, remarcar, horário, consulta`.
- Comercial — especialista — `preço, valor, orçamento, contratar`.

Validar:

- [ ] `Quero remarcar minha consulta` seleciona Agendamento.
- [ ] `Quero saber o valor` seleciona Comercial em uma conversa nova.
- [ ] Texto sem palavra específica usa Recepção.

## 4. Continuidade da conversa

- [ ] Após selecionar Agendamento, uma mensagem posterior contendo `valor` NÃO muda automaticamente para Comercial.
- [ ] O drawer da conversa mostra o agente atual.
- [ ] A troca manual para Comercial funciona.
- [ ] Mensagens posteriores permanecem com Comercial.

## 5. Mesmo agente em vários canais

- [ ] Vincular Agendamento ao WhatsApp Recepção.
- [ ] Vincular o mesmo Agendamento a outro WhatsApp.
- [ ] Ambos os cards mostram o agente.
- [ ] O cadastro continua contando como um único agente no limite do plano.
- [ ] Cada WhatsApp continua contando individualmente no limite de canais.

## 6. Canal sem IA

- [ ] Remover todos os agentes de um canal e salvar.
- [ ] O card mostra `Nenhum assistente vinculado`.
- [ ] Mensagens continuam sendo recebidas.
- [ ] Nenhuma resposta automática é disparada.
- [ ] Atendimento humano continua disponível.

## 7. Remoção de vínculo

- [ ] Criar uma conversa vinculada a um especialista.
- [ ] Remover esse especialista do canal.
- [ ] A fixação inválida da conversa é limpa.
- [ ] Na próxima mensagem elegível, o canal volta a escolher um agente válido.

## 8. Plano

- [ ] `instances` aparece como **Canais WhatsApp**.
- [ ] `agents` aparece como **Agentes especializados de IA**.
- [ ] `n8n_flows` aparece comercialmente como **Automações integradas**.
- [ ] Criar novo WhatsApp continua respeitando o limite `instances`.
- [ ] Criar novo agente continua respeitando o limite `agents`.
- [ ] Apenas vincular um agente existente a outro canal não consome nova unidade de agente.

## 9. Regressão

- [ ] Takeover humano continua impedindo IA.
- [ ] Cooldown continua sendo respeitado.
- [ ] Recuperação pós-horário continua usando a conversa/agente correto.
- [ ] Franquia IA RS continua baseada em respostas entregues com credencial RS.
- [ ] Credencial própria continua registrada sem consumir franquia RS.
- [ ] Agenda/pré-agendamento registra o agente roteado da conversa.
- [ ] Fila de reprocessamento respeita `conversations.ai_agent_id`.

## Limitação da validação local

A migration deve ser homologada no MySQL da VPS. O pacote pode ser validado estaticamente localmente, mas sem uma instância MySQL/Evolution real não é possível simular a integração completa de produção.
