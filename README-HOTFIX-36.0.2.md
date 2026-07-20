# HOTFIX 36.0.2 — Rolagem na tela de Contatos

## Correção

- O painel lateral de edição do contato agora respeita a altura disponível da tela.
- O conteúdo do formulário possui rolagem vertical própria no desktop.
- O cabeçalho permanece visível e o botão **Salvar alterações** fica acessível no rodapé.
- Em tablet e celular, o painel volta ao fluxo normal da página e acompanha a rolagem da tela.
- O formulário **Novo contato** também recebeu limite de altura e rolagem interna, evitando campos inacessíveis.
- A rolagem horizontal foi bloqueada nos dois formulários.

## Aplicação

1. Envie os arquivos internos do pacote ao GitHub.
2. Faça o redeploy no EasyPanel.
3. Reinicie o serviço do RS Connect.
4. Pressione `Ctrl + F5`.
5. Teste `/contacts` com uma lista extensa, abrindo um contato e também **Novo contato**.

Não existe migration nova.
