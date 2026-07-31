# Homologação final — RS Connect v36.10.6

## Critérios de aprovação

- relatório abre para toda a equipe e para um profissional;
- URLs usam `tenant_uuid` e `user_uuid` quando preenchidos;
- primeira resposta é atribuída ao usuário correto;
- ciclos encerrados e reabertos permanecem separados;
- horários da tela aparecem no fuso da empresa;
- CSV contém horário local e UTC;
- média geral corresponde ao agregado bruto dos ciclos;
- pendências refletem somente ciclos ativos com entrada do cliente e sem resposta humana;
- inconsistências de data retornam zero;
- profissional comum vê somente seus próprios indicadores;
- administrador vê a equipe da própria empresa;
- Super Admin seleciona uma empresa por vez.

## Cenário homologado esperado

Conversa com dois ciclos atribuídos à Rafaela:

- ciclo 1: 28 segundos, encerrado;
- ciclo 2: 16 segundos, ativo ou encerrado conforme o teste;
- média exata: 22 segundos.

Após estes critérios, a branch pode ser marcada como candidata a release.
