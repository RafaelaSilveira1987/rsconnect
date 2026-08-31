# RS Connect v36.25.2 — credenciais dos meios de pagamento

Correção da tela de Meios de pagamento para criar e editar gateways Asaas com segurança.

- mostra se API Key e token do webhook já estão cadastrados;
- mantém credenciais existentes quando os campos ficam vazios na edição;
- exige credenciais no navegador antes de tentar ativar;
- salva configurações incompletas como inativas em vez de descartar os dados;
- não reaproveita credenciais quando o provedor é trocado;
- diferencia claramente Sandbox e Produção.

Não há migration nova.
