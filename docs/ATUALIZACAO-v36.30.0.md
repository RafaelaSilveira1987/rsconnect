# RS Connect 36.30.0 — Fila e setores integrados ao atendimento

Esta versão inicia o ciclo de fechamento do escopo original, priorizando a distribuição do atendimento por setor e equipe.

## O que foi concluído

- ativação das rotas do módulo `/queue` e das ações de gestão de setores;
- inclusão de **Fila e setores** no menu conforme as permissões `queue.view` e `queue.manage`;
- nova migration `107_service_department_memberships.sql` para vínculo persistente usuário ↔ setor;
- gestão da equipe vinculada a cada setor na própria tela de Fila;
- transferência de uma conversa para setor diretamente no drawer de Conversas;
- distribuição operacional pela Fila com setor, responsável, prioridade e status;
- bloqueio de atribuição para profissional que não pertence ao setor selecionado;
- bloqueio de assunção de conversa por usuário comum quando a conversa pertence a outro setor;
- liberação do responsável anterior ao transferir a conversa para outro setor;
- pausa da IA e retorno da conversa para `waiting_agent` quando direcionada a um setor sem responsável;
- exibição do setor atual na lista e nos dados da conversa;
- inclusão do setor operacional no contexto estruturado entregue à IA;
- reforço de isolamento por `tenant_id` nos joins operacionais alterados nesta versão.

## Migration obrigatória

```bash
php bin/migrate.php run
php bin/migrate.php status
```

A migration obrigatória desta versão é:

`107_service_department_memberships.sql`

## Fluxo esperado

1. Administrador acessa **Fila e setores**.
2. Cria/ativa os setores necessários.
3. Vincula os usuários que podem atender cada setor.
4. Uma conversa pode ser transferida para um setor.
5. A transferência libera o responsável anterior e pausa a IA.
6. A conversa permanece na fila do setor até ser assumida ou distribuída para um membro compatível.
7. O setor passa a compor o contexto operacional do atendimento.

## Próximo item do ciclo

Concluir o módulo **Campanhas**, registrando suas rotas/menu e homologando o fluxo audiência → aprovação → envio → resultado com proteções operacionais.
