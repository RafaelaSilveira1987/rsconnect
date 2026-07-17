# RS Connect — ZIP 34.5

## Objetivo

Corrigir o contexto administrativo dos assistentes e garantir, na plataforma, a ordem do atendimento antes da criação de pré-agendamentos.

## 1. Assistentes no Admin RS

A página `/agents` agora possui seletor de empresa para o Super Admin. A empresa escolhida fica lembrada na sessão e todos os formulários de criação e edição utilizam o `tenant_id` correto.

Os botões da Saúde e diagnóstico abrem a página com o filtro correto:

```text
/agents?tenant_id=ID_DA_EMPRESA
```

## 2. Grupos de contato

Grupos disponíveis:

- Não identificado;
- Novo interessado;
- Paciente atual;
- Familiar;
- Casal;
- Outro grupo.

O grupo pode ser definido em **Contatos** ou na gaveta **Dados da conversa**.

## 3. Estado do atendimento

A plataforma registra por conversa:

- etapa atual;
- demanda ainda não coletada, coletada, recusada ou dispensada;
- resumo da demanda;
- identificação de paciente atual;
- última intenção detectada.

## 4. Trava do pré-agendamento

Um novo pré-agendamento só é criado quando:

- a demanda foi coletada; ou
- o contato preferiu não informar; ou
- a regra do grupo dispensou a demanda; ou
- o contato é paciente atual, está remarcando e a regra permite remarcação sem repetir a queixa.

Palavras isoladas como `horário`, `agenda` ou `disponibilidade` não criam mais o pré-agendamento antes dessa etapa.

Pré-agendamentos que já existiam continuam aceitando complementos de data e horário.

## 5. Contexto enviado à IA

O prompt final recebe automaticamente:

- classificação do contato;
- grupo;
- tags;
- etapa atual;
- situação da demanda;
- resumo da demanda;
- regras específicas do grupo.

Também são reforçadas as regras de não repetir perguntas e fazer somente uma pergunta por mensagem.

## 6. Regras por grupo

Em **Assistentes de IA → Regras por grupo de contato**, configure:

- permitir pré-agendamento;
- exigir demanda antes da agenda;
- permitir remarcação sem repetir a demanda;
- orientação específica para o grupo.

## Aplicação

1. Envie o conteúdo ao GitHub.
2. Execute no Adminer:

```sql
database/migrations/040_conversation_flow_contact_groups.sql
```

3. Faça o redeploy.
4. Reinicie o serviço do RS Connect.
5. Pressione `Ctrl + F5`.

## Teste recomendado

1. Abra `/agents` como Super Admin e selecione a empresa Mariana Bernardes.
2. Confirme que o assistente e o prompt aparecem.
3. Em Contatos, classifique um número como **Novo interessado**.
4. Envie apenas: `Tem horário amanhã?`.
5. Confirme que não foi criado pré-agendamento e que a IA solicita a demanda.
6. Envie uma mensagem com a demanda e depois informe data/horário.
7. Confirme a criação do pré-agendamento com o resumo da demanda.
8. Classifique outro contato como **Paciente atual** e teste uma remarcação.

## Diagnóstico

```sql
database/diagnostics/conversation_flow_contact_groups_check.sql
```
