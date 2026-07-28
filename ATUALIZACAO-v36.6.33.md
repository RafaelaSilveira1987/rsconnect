# Atualização RS Connect 36.6.33

## Objetivo

Corrigir a busca da página **Contatos → Base de contatos** sem alterar os demais módulos.

## O que foi corrigido

- elimina o reaproveitamento do mesmo placeholder SQL em vários campos, incompatível com PDO usando prepares nativos;
- pesquisa por nome, telefone, e-mail, empresa, observações e tags;
- normaliza o telefone, permitindo busca com ou sem máscara, espaços, DDI e DDD;
- aceita termos compostos;
- aplica a busca automaticamente após uma breve pausa na digitação;
- aplica imediatamente os filtros de classificação, grupo e empresa;
- apresenta estado vazio quando não houver resultado.

## Implantação

1. Publicar o pacote 36.6.33.
2. Fazer o deploy normal no EasyPanel.
3. Atualizar a página com `Ctrl + F5`.
4. Não há migration nova.

## Banco de dados

A migration base permanece:

```text
059_contact_identity_confidence.sql
```
