# RS Connect 36.6.29 — Busca confiável e avatar do contato

## Objetivo

Corrigir a pesquisa da Caixa de Entrada e enriquecer visualmente as conversas com a foto real do contato quando a Evolution/WhatsApp disponibilizar esse dado, sem alterar regras de IA, agenda, comunicação, atendimento ou planos.

## O que muda

### Busca de Conversas

A pesquisa passa a considerar:

- nome do contato;
- telefone, inclusive quando digitado com máscara, espaços, parênteses ou hífen;
- e-mail;
- empresa;
- última mensagem;
- conteúdo do histórico da conversa.

A busca também passa a atualizar a lista enquanto o usuário digita, com pequeno debounce. O botão **Filtrar** continua funcionando normalmente.

### Avatar do contato

O RS Connect reutiliza o campo `contacts.avatar_url`, já existente na base.

Quando disponível, a foto é obtida pela Evolution e exibida:

- na lista de conversas;
- no cabeçalho da conversa selecionada.

Quando a foto não estiver disponível por privacidade, ausência de imagem ou falha temporária, as iniciais continuam sendo usadas como fallback. A foto nunca é requisito para o atendimento funcionar.

O webhook também passa a aceitar o evento `contacts.upsert` para atualizar a foto quando a Evolution informar mudança de perfil.

## Banco de dados

Não há migration nova.

A migration base permanece:

`059_contact_identity_confidence.sql`

O campo `contacts.avatar_url` já existe desde a migration 003.

## Deploy

1. Atualize os arquivos da aplicação.
2. Faça redeploy/restart no EasyPanel.
3. Faça `Ctrl + F5` no navegador.
4. Não é necessário executar SQL.

## Teste rápido

1. Abra **Conversas**.
2. Pesquise um contato pelo nome.
3. Pesquise o mesmo contato por parte do telefone usando máscara ou hífen.
4. Pesquise por um trecho de mensagem antiga que não seja necessariamente a última mensagem.
5. Abra um contato com foto disponível no WhatsApp.
6. Confirme a foto na lista e no cabeçalho.
7. Abra um contato sem foto e confirme que as iniciais continuam aparecendo normalmente.
