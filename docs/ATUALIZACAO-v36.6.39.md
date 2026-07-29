# Atualização RS Connect 36.6.39

## Assinatura humana visível para o contato no WhatsApp

Esta versão corrige o caso em que o nome do atendente aparecia apenas na interface do RS Connect, principalmente quando a mensagem era enviada por um Super Admin global.

## Como funciona

A mensagem manual enviada pela tela de Conversas passa a chegar ao contato assim:

```text
*Rafaela — Atendimento*
Vou verificar sua solicitação.
```

O texto entre asteriscos é exibido em negrito no WhatsApp.

A assinatura é adicionada somente quando:

1. a empresa habilita **Identificar atendente no WhatsApp**;
2. o usuário habilita **Permitir assinatura no WhatsApp**;
3. a mensagem é enviada manualmente por uma pessoa na tela de Conversas.

Respostas de IA, agenda, cobrança e outras automações não recebem assinatura humana.

## Correção do Super Admin

Antes, o serviço exigia que `users.tenant_id` fosse igual ao `tenant_id` da conversa. Usuários Super Admin possuem `tenant_id = NULL`, então eram descartados e o texto original era enviado sem nome.

Agora o serviço aceita:

- usuário da própria empresa; ou
- Super Admin global ativo.

O Super Admin também pode ter nome público, função e assinatura habilitada na tela **Usuários**.

## Deploy

Implante o ZIP completo ou copie os arquivos do patch sobre o projeto atual e faça redeploy/restart.

Não há migration nova.

## Teste

1. Em **Minha empresa → Configurações da empresa**, habilite **Identificar atendente no WhatsApp**.
2. Escolha o formato **Nome + função**.
3. Em **Equipe e acessos** ou **Usuários**, preencha o nome e a função pública e habilite a assinatura.
4. Envie uma mensagem manual pela tela de Conversas.
5. Confirme no celular do contato que o nome aparece na primeira linha.

Quando o envio é feito por AJAX, a resposta técnica também informa:

```json
{"human_signature_applied": true}
```

## Diagnóstico no Adminer

O arquivo abaixo lista a configuração da empresa, dos usuários e o texto efetivamente entregue à Evolution:

```text
database/diagnostics/human_signature_delivery_v36.6.39.sql
```

Na coluna `delivered_content`, o resultado esperado é semelhante a:

```text
*Rafaela — Atendimento*
Vou verificar sua solicitação.
```
