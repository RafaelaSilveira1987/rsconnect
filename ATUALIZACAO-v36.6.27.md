# RS Connect 36.6.27 — Central de comunicação refinada

## Objetivo

Corrigir a entrega visual dos comunicados internos para empresas clientes e reorganizar a Central de comunicação do Super Admin para uma operação mais clara.

## Correção funcional principal

Na 36.6.25 o hub de comunicação do cliente dependia de `notifications.view` e da visibilidade do módulo `notifications`. Isso permitia que o comunicado fosse criado e aparecesse no histórico do sininho, mas a caixa flutuante não fosse montada para determinados perfis/empresas.

A partir da 36.6.27, comunicação institucional da RS é tratada como canal da plataforma:

- qualquer usuário autenticado vinculado a uma empresa pode receber a caixa interna;
- o hub não depende da exibição do menu Notificações;
- os endpoints de inbox/leitura/confirmação/resposta exigem autenticação e vínculo com tenant, mas não a permissão `notifications.view`;
- a primeira mensagem não lida é carregada no servidor e renderizada imediatamente;
- o polling JavaScript continua atualizando o inbox a cada poucos segundos, mas deixou de ser a única forma de fazer a caixa aparecer.

## Cliente

Quando existe comunicado não lido:

1. o layout consulta a Central de comunicação no servidor;
2. a caixa flutuante já entra no HTML da página;
3. minimizar não marca leitura;
4. ao abrir, o drawer é exibido e a mensagem é marcada como lida;
5. confirmação e resposta seguem o `response_mode` do comunicado;
6. respostas novas da RS tornam o tópico não lido novamente.

Links do histórico/sininho que possuem `communication_id` agora abrem diretamente o drawer correspondente.

## Super Admin

A tela `Operação RS > Comunicados` foi reorganizada em três abas:

- **Novo comunicado** — conteúdo, público/interação, canais e validade;
- **Histórico** — cartões com empresas, lidas, pendentes, respostas e canais externos;
- **Respostas** — conversa administrativa RS ↔ empresa cliente.

O preview foi mantido e refinado para simular a caixa flutuante e o botão compacto do cliente.

## Banco de dados

Não há migration nova nesta versão.

A base consolidada permanece na migration:

`059_contact_identity_confidence.sql`

A Central de comunicação exige que a `058_client_communication_center.sql` já tenha sido aplicada.

## Deploy

1. Publicar os arquivos da 36.6.27.
2. Fazer redeploy/restart da aplicação.
3. Fazer `Ctrl + F5` no navegador para carregar `app.css?v=36.6.27` e `app.js?v=36.6.27`.
4. Não é necessário executar SQL novo.

## Teste mínimo

1. Super Admin: `Operação RS > Comunicados > Novo comunicado`.
2. Enviar uma informação normal para uma única empresa de teste.
3. Entrar nessa empresa com um usuário que tenha o menu Notificações visível e confirmar a caixa.
4. Marcar o menu Notificações como oculto para a empresa/perfil e repetir com outro comunicado.
5. A caixa institucional deve continuar aparecendo.
6. Minimizar e recarregar a página: o tópico permanece não lido.
7. Abrir a mensagem: a leitura passa a ser registrada e a caixa some quando não houver mais pendências.
8. Testar comunicado com confirmação e comunicado com resposta.
