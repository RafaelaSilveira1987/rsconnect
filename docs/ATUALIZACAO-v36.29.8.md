# RS Connect 36.29.8 — Organização inteligente e telas operacionais

Esta versão consolida a terceira rodada visual em **Conversas e Contatos**, incorpora os ajustes apontados nas telas de Agentes, Instâncias e Configurações e transforma a Organização do contato em contexto operacional efetivo para a IA.

## Ajustes dos layouts revisados

- **Instâncias / Agentes e roteamento:** o campo de prioridade deixa de esticar verticalmente e mantém largura compacta ao lado das palavras de roteamento; em mobile, os campos passam para uma coluna.
- **Configurações / Menus do cliente:** `Menu` e `Acesso` passam a ocupar colunas previsíveis e alinhadas, sem quebras aleatórias entre os cards; em telas estreitas os controles se reorganizam sem perder os checkboxes nativos.
- **Agentes / Conversa natural:** eyebrow, título e descrição recebem separação visual consistente.
- **Agentes / Ordem do atendimento:** as etapas passam a ser apresentadas em uma única sequência vertical. A ordem funcional e os controles de subir/descer continuam os mesmos.

## Contatos e Organização

A classificação deixou de ser apenas um dado visual. O RS Connect agora deriva um **perfil operacional de relacionamento** a partir de classificação, grupo e tags e o envia ao contexto da IA.

- `Lead / novo contato` + `Novo interessado`: mantém qualificação progressiva de novo lead.
- `Cliente/Paciente atual` + `Cliente atual`: prioriza continuidade e não reinicia captação/qualificação.
- `Cliente/Paciente atual` + `Paciente atual`: prioriza histórico, acompanhamento, dúvidas e agenda sem exigir novamente dados ou motivo já conhecidos.
- `Familiar`, `Casal` e outros grupos: preservam o contexto específico do atendimento.
- `Contato inativo`: não é tratado automaticamente nem como relacionamento ativo nem como novo lead.

Ao escolher `Cliente atual` ou `Paciente atual` como grupo, o backend normaliza a classificação para relacionamento atual. Se a classificação for marcada como relacionamento atual e o grupo ainda estiver `Não identificado`/`Novo interessado`, o grupo é normalizado para `Cliente atual`. A mesma regra é usada ao editar o contato pela tela de Conversas.

## IA e CRM

- O prompt passa a receber `Perfil operacional`, descrição do relacionamento e uma regra explícita de condução.
- Cliente/paciente atual não volta para a triagem de novo lead somente por abrir outra conversa no WhatsApp.
- Uma conversa rotineira de cliente/paciente não cria automaticamente uma nova oportunidade comercial.
- Uma oportunidade já aberta continua sendo preservada.
- Se um relacionamento atual demonstrar intenção comercial explícita de nova compra/contratação/orçamento, o CRM ainda pode abrir uma nova oportunidade; portanto a correção não bloqueia venda adicional.
- A simulação de agentes passa a usar o grupo válido `interested` para novo lead.

## Tela de Contatos

- bloco de dados com classificação operacional mais legível;
- tags exibidas em pills, com menor ruído visual;
- drawer de criação/edição reorganizado em seções;
- prévia “Como a IA entende este cadastro” reage à classificação/grupo antes do salvamento;
- cabeçalho do contato mostra o perfil de relacionamento;
- em mobile, cada linha da tabela vira um card com rótulos próprios, evitando tabela espremida e rolagem difícil.

## Tela de Conversas

O bloco de contato passa a reutilizar os mesmos rótulos, normalização e prévia de Organização da Base de Contatos. Assim, alterar a classificação durante uma conversa produz exatamente o mesmo contexto utilizado pela IA na tela de Contatos.

## Compatibilidade

- nenhuma migration nova;
- migration obrigatória permanece `106_agent_message_grouping_context_priority.sql`;
- rotas, actions, IDs funcionais e campos persistidos existentes foram preservados;
- alterações de comportamento foram concentradas na classificação/continuidade de relacionamento e na criação automática de oportunidade comercial.
