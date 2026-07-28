# RS Connect 36.6.31 — Novo atendimento em drawer

## Objetivo
Corrigir o formulário `+ Nova` da tela de Conversas, que podia ser recortado por estar dentro da coluna estreita da Caixa de Entrada.

## Alterações
- `+ Nova` abre um drawer lateral independente da Caixa de Entrada.
- Busca preventiva por nome/telefone permanece disponível.
- Seleção de instância, telefone, nome e primeira mensagem continuam com o mesmo backend.
- Conversa existente continua sendo identificada antes do envio.
- Cancelar, clicar no backdrop ou pressionar Esc fecha o drawer sem mover a lista.
- Em telas pequenas o formulário ocupa a largura total.

## Banco de dados
Não há migration nova. A base continua em `059_contact_identity_confidence.sql`.

## Atualização
1. Faça o deploy do pacote 36.6.31.
2. Faça `Ctrl + F5` na tela de Conversas.
3. Clique em `+ Nova` e valide o drawer em desktop e largura reduzida.
