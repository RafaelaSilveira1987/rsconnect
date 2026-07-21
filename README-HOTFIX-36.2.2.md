# HOTFIX 36.2.2 — Excluir também remove do Google Agenda

Corrige a ação **Excluir** da Agenda.

## Problema corrigido

Nos agendamentos originados de eventos `VAGO`, a exclusão removia o registro do RS Connect, mas executava `release` no Google Agenda. Com isso, o evento era restaurado como `VAGO` e continuava visível no calendário.

## Novo comportamento

Ao clicar em **Excluir**:

1. o RS Connect identifica se existe um evento Google vinculado;
2. para `google_marked_slots`, envia a ação `delete` ao workflow Eventos VAGO;
3. o workflow marca o evento como `cancelled` (estado de evento excluído no Google Calendar);
4. o callback confirma `state: deleted`;
5. somente depois o registro local é apagado;
6. nenhuma mensagem é enviada ao contato.

Se o Google ou o n8n não confirmarem a exclusão, o registro local é preservado e o painel mostra o erro. Isso evita eventos órfãos.

## Atualização do n8n obrigatória

Desative temporariamente o workflow **Eventos VAGO** atual e importe a versão atualizada usando:

```text
docs/n8n_templates/template-agenda-google-eventos-vago.json
```

Depois confira novamente a credencial Google nos nós, ative o workflow novo e só então remova o antigo. O caminho do webhook foi preservado.

## Instalação

1. Aplicar os arquivos sobre o HOTFIX 36.2.1.
2. Atualizar/importar o workflow Eventos VAGO no n8n.
3. Fazer deploy no EasyPanel.
4. Confirmar `HOTFIX 36.2.2` em **Central de operação → Status do sistema**.

Não existe migration nova.
