# RS Connect v36.30.1 — Fila opcional e frontend revisado

## Objetivo

Fechar a primeira evolução da distribuição operacional com duas correções: tornar **Fila e setores** opcional por empresa e reorganizar completamente a experiência visual da fila em desktop, tablet e celular.

## Alterações funcionais

- Novo módulo técnico `queue` no controle de módulos da empresa.
- A atualização preserva o comportamento atual: a fila continua disponível para empresas existentes, mas passa a poder ser desligada pela própria empresa quando não fizer sentido para a operação.
- O administrador do cliente pode ativar/desativar **Fila e setores** em **Minha empresa**.
- Ao ativar, acesso e visibilidade do menu são ligados juntos.
- Ao desativar, o menu e as rotas da fila são bloqueados para o cliente.
- O atendimento direto por profissional/IA continua funcionando sem exigir setor.
- Vínculos de setor existentes são preservados, mas deixam de restringir o atendimento enquanto o recurso estiver desligado.
- A transferência de conversa para setor é recusada pelo backend quando o recurso estiver desativado.

## Correções de frontend

- Removido o popover de distribuição que deslocava/recortava a tabela.
- A ação **Distribuir** agora abre um drawer lateral reutilizando o padrão visual do RS Connect.
- Tabela em desktop passa a usar layout fixo e previsível, sem o contato sumir ao abrir a distribuição.
- Em tablet/celular, cada conversa vira um card com rótulos claros.
- Ações **Abrir** e **Distribuir** permanecem alinhadas e acessíveis.
- Painel de setores reduzido para uma coluna lateral mais limpa.
- Cadastro de novo setor virou seção recolhível.
- Gestão da equipe de cada setor virou seção recolhível, evitando dezenas de checkboxes expostos ao mesmo tempo.
- Nome e função do usuário passam a ser exibidos em linhas separadas dentro do checkbox.
- Drawer filtra responsáveis conforme o setor selecionado.

## Banco de dados

Não há migration nova na v36.30.1.

A estrutura de vínculo usuário ↔ setor continua dependendo de:

`107_service_department_memberships.sql`

## Homologação recomendada

1. Aplicar/confirmar a migration 107.
2. Entrar em **Minha empresa** e ativar **Fila e setores**.
3. Criar dois setores e vincular usuários diferentes.
4. Abrir **Fila e setores**, distribuir uma conversa e conferir o drawer.
5. Abrir **Conversas** e validar a transferência por setor.
6. Desativar **Fila e setores** e confirmar que o menu desaparece e o atendimento direto continua funcionando.
