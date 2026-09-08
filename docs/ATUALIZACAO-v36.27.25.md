# RS Connect 36.27.25 — Agenda com Prompt Studio ou formulário

## Objetivo

Separar a **lógica técnica da agenda** da **forma de conversar com o cliente**.

A disponibilidade, pré-reserva e confirmação continuam sendo validadas pelo RS Connect. A empresa pode escolher como o assistente faz as perguntas de coleta:

- **Prompt Studio — conversa natural:** o assistente usa as instruções do Prompt Studio para perguntar apenas o que estiver faltando (dia/horário e/ou modalidade).
- **Formulário — textos configurados:** o RS Connect envia os textos cadastrados nas Configurações da empresa, sem depender da redação livre da IA.

## Novo fluxo recomendado

1. Cliente demonstra intenção de agendar.
2. Se faltarem dia/horário e modalidade, a conversa coleta esses dados.
3. Quando a preferência estiver completa, o RS Connect informa que consultará a disponibilidade.
4. A agenda selecionada (interna ou integração habilitada) é consultada.
5. Apenas horários reais são apresentados/pré-reservados.
6. A confirmação final respeita a regra de aprovação humana da empresa.

## Configuração

Acesse **Configurações da empresa → Agenda → Pré-agendamento e mensagens**.

Em **Forma de conduzir as perguntas da agenda**, escolha:

- `Prompt Studio — conversa natural`; ou
- `Formulário — textos configurados`.

No formulário podem ser personalizados:

- pergunta inicial quando ainda faltam dia/horário e modalidade;
- pergunta de dia/horário;
- pergunta de modalidade;
- mensagem enquanto consulta disponibilidade;
- aprovação, recusa e remarcação;
- opções disponíveis;
- horário selecionado;
- ausência de disponibilidade;
- escolha inválida.

## Migration

Aplicar:

```text
102_agenda_prompt_or_form_messages.sql
```

A migration preserva mensagens personalizadas existentes e substitui apenas textos padrão antigos/burocráticos pelos novos defaults naturais.

## Compatibilidade e segurança

- O modo padrão após a migration é `form`, evitando alteração inesperada em empresas existentes.
- O modo Prompt Studio só delega a **redação das perguntas de coleta** à IA.
- Disponibilidade, slot selecionado, pré-reserva e confirmação continuam determinísticos e usam textos configurados/fallback para não permitir que a IA invente fatos de agenda.
