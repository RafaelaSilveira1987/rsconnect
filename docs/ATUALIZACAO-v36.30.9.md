# RS Connect 36.30.9 — Dados da conversa aprimorados

## Objetivo

Melhorar a leitura da lateral **Dados da conversa** sem alterar qualquer regra de atendimento, IA, fila, CRM ou agenda.

## Alterações

- ação do cabeçalho renomeada de `Dados do lead` para `Dados da conversa`;
- identidade do contato com avatar, telefone e conexão no cabeçalho do drawer;
- resumo operacional com status, modo, responsável e assistente;
- relacionamento Cliente/Paciente/Lead evidenciado sem duplicar campos editáveis;
- atalhos internos para as principais seções do drawer;
- `Validação efetiva` deixa de parecer um formulário técnico e passa a usar cartões de leitura;
- intenção `conversation` é exibida como **Conversa geral** e outras intenções conhecidas recebem rótulos em português;
- tags consideradas pela IA passam a ser chips;
- ajustes responsivos para desktop, tablet e celular.

## Compatibilidade

Não há migration nova. A migration obrigatória permanece `110_conversation_lifecycle_e2e_consistency.sql`.

Não foram alterados:

- endpoints;
- `name`, `id` ou `data-*` funcionais;
- formulários de classificação, status, atribuição, notas ou roteamento;
- lógica do ciclo de atendimento validada na v36.30.8.
