# RS Connect 36.6.13 — Canais WhatsApp e roteamento de agentes

## Objetivo

Esta versão transforma a antiga relação direta `1 assistente -> 1 instância` em um modelo de canais de atendimento:

- uma empresa pode possuir vários números WhatsApp, respeitando o limite do plano;
- todos os números ficam na mesma tela **Canais WhatsApp**;
- cada número é um canal;
- um canal pode ter vários agentes de IA;
- um agente pode atuar em vários canais;
- cada canal pode ter um agente principal e agentes especialistas;
- a conversa fica vinculada ao agente escolhido para preservar contexto e personalidade;
- o operador pode trocar manualmente o agente de uma conversa;
- um canal também pode ficar sem IA e operar somente com atendimento humano.

## Migration obrigatória

Após o deploy execute:

```sql
SOURCE database/migrations/055_multi_whatsapp_agent_routing.sql;
```

A migration cria `ai_agent_instance_bindings`, adiciona `conversations.ai_agent_id`, converte vínculos legados e preserva as conversas existentes.

## Como fica a tela

A empresa acessa **Canais WhatsApp** e visualiza todos os números em uma única página.

Cada card informa:

- situação da conexão;
- quantidade de agentes vinculados;
- agente principal;
- total de conversas do canal;
- configuração de agentes e roteamento;
- acesso às conversas daquele número.

## Como funciona o roteamento

A ordem é:

1. se a conversa já possui agente, mantém o mesmo agente;
2. se é uma conversa ainda sem agente, compara a primeira mensagem com as palavras de roteamento dos especialistas;
3. se houver correspondência, escolhe o especialista com melhor combinação;
4. sem correspondência, usa o agente principal do canal;
5. a conversa fica fixada no agente escolhido;
6. uma troca manual no drawer da conversa substitui essa fixação.

Exemplo:

- WhatsApp Recepção
  - Rafa — Recepção — principal
  - Bia — Agendamento — palavras: `agendar, remarcar, horário, consulta`
  - Leo — Comercial — palavras: `preço, valor, orçamento, contratar`

Uma nova conversa dizendo `quero remarcar minha consulta` inicia com Bia e permanece com Bia até uma troca manual ou remoção do vínculo.

## Limites do plano

A contabilização fica conceitualmente assim:

- `instances`: **Canais WhatsApp** — cada número conectado consome 1 unidade;
- `agents`: **Agentes especializados de IA** — o mesmo agente vinculado a vários números continua consumindo 1 agente;
- `n8n_flows`: apresentado como **Automações integradas**;
- `ai_interactions_month`: continua sendo a **Franquia IA RS**.

A versão não altera preços nem quantidades dos planos atuais.

## Compatibilidade

`ai_agents.instance_id` foi mantido temporariamente para compatibilidade com rotinas antigas. O novo vínculo N:N é a fonte de verdade para o roteamento dos canais após a migration 055.

A seção **Recuperação técnica (legado)** permanece disponível para correções de associações antigas, mas não deve ser usada como configuração normal de roteamento.

## Teste recomendado

1. Aplicar a migration 055.
2. Abrir **Canais WhatsApp**.
3. Em um canal, vincular Recepção como principal e Agendamento como especialista.
4. Cadastrar `agendar, remarcar, horário, consulta` nas palavras de Agendamento.
5. Enviar de um contato novo: `Quero remarcar meu horário`.
6. Confirmar que a conversa fica vinculada a Agendamento.
7. Enviar depois uma mensagem de outro assunto e confirmar que o agente não muda sozinho.
8. No drawer da conversa, trocar manualmente o agente.
9. Vincular o mesmo agente a um segundo WhatsApp e confirmar que o limite de agentes não aumenta por causa do novo vínculo.
10. Deixar um canal sem agentes e confirmar que mensagens continuam chegando para atendimento humano.
