# Validação técnica — ENT-028 PagBank tax_id hotfix v36.20.13.2

## Problema

O Checkout PagBank autenticava corretamente, mas rejeitava `customer.tax_id` porque a implementação anterior validava apenas o tamanho de 11 ou 14 dígitos, sem conferir os dígitos verificadores do CPF/CNPJ.

## Solução

- normalização para somente dígitos;
- validação completa de CPF e CNPJ;
- rejeição de sequências repetidas;
- omissão de documento inválido em checkout editável;
- uma única nova tentativa sem `customer.tax_id` quando o PagBank recusar especificamente esse campo.

## Validações

- 308 arquivos PHP sem erro de sintaxe;
- JavaScript sem erro de sintaxe;
- 10 verificações específicas do hotfix aprovadas;
- 19 verificações da ENT-028 aprovadas;
- suíte completa: 78 aprovados, 9 falhas históricas, 87 total;
- nenhuma migration nova.
