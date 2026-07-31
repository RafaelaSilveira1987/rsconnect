# RS Connect v36.10.7 — Conversas abertas somente por seleção

## Objetivo

Ao acessar **Conversas**, o RS Connect passa a carregar somente a lista. O painel de atendimento permanece vazio até que o usuário clique em um contato.

## Comportamento

- entrar em `/conversations` não seleciona a primeira conversa;
- nenhuma conversa é marcada como lida por abertura automática;
- o polling continua atualizando a lista e os contadores;
- clicar em uma conversa abre o histórico normalmente;
- links diretos com `conversation_uuid` continuam abrindo a conversa indicada;
- ações como enviar, transferir, encerrar e voltar da agenda continuam retornando à conversa correspondente.

## Instalação

Aplique o patch sobre a v36.10.6, faça commit e novo deploy. Não há migration. Atualize o navegador com `Ctrl + F5`.

## Validação

1. Entre em Conversas pelo menu.
2. Confirme que nenhuma linha aparece selecionada.
3. Confirme o texto “Selecione uma conversa” no painel central.
4. Clique em um contato e valide a abertura do histórico.
5. Volte ao menu Conversas sem UUID e confirme novamente o estado vazio.
