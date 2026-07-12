# RS Connect — ZIP 20

## Equipe de Atendimento, Fila e Distribuição

Este pacote adiciona a camada operacional de atendimento para empresas que possuem mais de uma pessoa atendendo conversas no WhatsApp.

## Principais recursos

- Novo menu **Fila de atendimento**.
- Métricas rápidas da operação:
  - total na fila;
  - pendentes;
  - em atendimento;
  - sem responsável;
  - prioridade alta/urgente;
  - conversas com mensagens não lidas.
- Cadastro de setores por empresa:
  - Comercial;
  - Suporte;
  - Financeiro;
  - outros setores personalizados.
- Distribuição de conversa por:
  - setor;
  - responsável;
  - prioridade;
  - status operacional.
- Novos status operacionais:
  - Novo;
  - Aguardando atendimento;
  - Em atendimento;
  - Aguardando cliente;
  - Resolvido;
  - Arquivado.
- Novas prioridades:
  - Baixa;
  - Normal;
  - Alta;
  - Urgente.
- Anotações internas dentro da conversa, visíveis apenas para a equipe.
- Melhorias na gaveta de dados da conversa.
- Integração da fila com a tela de Conversas.
- Atualização do webhook Evolution para reabrir conversas resolvidas quando o lead voltar a chamar.

## Como atualizar

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. No Adminer, execute:

```text
database/migrations/020_queue_team_distribution.sql
```

4. Pressione `Ctrl + F5` no navegador.

## Como testar

1. Acesse `/queue` ou o menu **Fila de atendimento**.
2. Veja se os setores padrão foram criados.
3. Abra uma conversa.
4. Clique em **Dados do lead**.
5. Defina setor, responsável, status e prioridade.
6. Adicione uma anotação interna.
7. Volte para **Fila de atendimento** e confira a conversa atualizada.

## Observação

A IA continua funcionando normalmente. A fila serve para operação humana: triagem, distribuição, priorização, resolução e acompanhamento.
