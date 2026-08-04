# RS Connect — Relatórios V2 e identificação do usuário

## O que foi implementado

- novo painel de relatórios do cliente, mantendo os dados, filtros, permissões e exportações existentes;
- novo painel executivo do Super Admin, utilizando os serviços e métricas já disponíveis;
- identificação do usuário conectado no topo da aplicação, com iniciais, nome e função;
- estilo visual isolado para os relatórios V2, sem alterar a aparência das demais telas;
- acesso temporário ao layout anterior pela opção `?layout=legacy` na URL de relatórios.

## Segurança da alteração

- nenhuma migration foi adicionada;
- nenhuma tabela ou coluna foi alterada;
- as consultas e serviços de relatório existentes foram preservados;
- o controller somente escolhe entre a nova view e a view anterior;
- as rotas, permissões, exportações e isolamento por empresa continuam os mesmos.

## Arquivos novos

- `app/Views/reports/index_v2.php`
- `app/Views/reports/admin_v2.php`
- `public/assets/css/reports-v2.css`

## Arquivos ajustados

- `app/Controllers/ReportController.php`
- `app/Views/layouts/app.php`
- `public/assets/css/app.css`

## Homologação executada

- validação de sintaxe PHP nos arquivos alterados;
- execução completa dos testes `tests/*-smoke.php`;
- todos os testes concluídos com sucesso.

## Retorno rápido ao visual anterior

Na tela de relatórios, acrescente `?layout=legacy` à rota. Exemplo:

```text
/reports?layout=legacy
```

Caso a URL já possua filtros, use `&layout=legacy`.
