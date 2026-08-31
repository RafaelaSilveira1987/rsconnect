# Validação — RS Connect v36.24.3

## Escopo

Correção da criação do Checkout recorrente Asaas quando a API responde `O campo name é inválido`.

## Alterações verificadas

- nome do item alterado para `RS Connect Plano Inicial`, sem travessão tipográfico;
- nome do responsável normalizado para letras e espaços;
- `customerData` enviado somente quando o nome possui pelo menos duas palavras;
- fallback único: se o Asaas ainda rejeitar especificamente o campo `name`, o sistema repete a criação sem `customerData`;
- demais erros continuam sendo devolvidos sem repetição indiscriminada;
- User-Agent atualizado para `RS-Connect/36.24.3`.

## Resultados

- 108 de 108 testes de fumaça aprovados;
- 352 de 352 arquivos PHP com sintaxe válida;
- 4 arquivos JavaScript validados pelo Node.js;
- manifesto com 101 migrations validado;
- parser reconheceu 1.988 instruções SQL;
- nenhuma migration nova;
- ZIPs validados após compactação.

## Limite da validação

A suíte confirmou a montagem segura do payload e o fallback, mas não executou uma cobrança real contra a conta Sandbox do usuário, pois as credenciais não estão disponíveis no ambiente de validação.
