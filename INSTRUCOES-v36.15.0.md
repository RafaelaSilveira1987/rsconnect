# RS Connect v36.15.0 — Painel executivo das empresas clientes

## Objetivo

Aplicar às empresas clientes o mesmo padrão executivo validado na RS Admin, preservando isolamento por tenant e linguagem simples.

## Instalação

1. Crie a branch `feature/painel-executivo-clientes`.
2. Extraia o pacote completo na raiz do projeto, preservando o `.env`.
3. Faça commit e push.
4. No EasyPanel, aponte temporariamente o serviço para a nova branch e execute o rebuild.

## Banco de dados

Não existe migration nova. A última migration permanece `074_conversation_message_attachments.sql`.

## Homologação

- Entre com um usuário de empresa.
- Abra **Relatórios → Visão geral**.
- Teste hoje, últimos 7 dias e últimos 30 dias.
- Confira os oito cards principais.
- Compare os números com **Equipe e profissionais**, **Agenda**, **Conversas** e **CRM**.
- Teste os estados sem dados, exportação e impressão.
- Confirme que um usuário de outra empresa não consegue acessar dados do tenant testado.
