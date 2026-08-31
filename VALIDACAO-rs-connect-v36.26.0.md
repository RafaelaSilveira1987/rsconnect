# Validação — RS Connect v36.26.0

## Resultado

- suíte funcional: **115 de 115 testes aprovados**;
- sintaxe PHP: **355 arquivos válidos**;
- sintaxe JavaScript: **4 arquivos válidos**;
- manifesto: **103 migrations de subida**;
- parser SQL: **2.051 instruções reconhecidas**;
- rollbacks isolados: **1**;
- verificação de PDO nativo: **1.003 consultas estáticas sem placeholders nomeados reutilizados**.

## Pontos verificados

- criação e edição de cupons;
- percentual e valor fixo;
- primeira cobrança e desconto recorrente;
- cartão, Pix ou ambos;
- validade e limites de uso;
- validação pública com CSRF;
- persistência do desconto na inscrição;
- valor líquido enviado ao Checkout;
- restauração do valor integral após a primeira cobrança;
- exibição do cupom no portal do cliente;
- migration idempotente e manifesto atualizado;
- compatibilidade com os fluxos anteriores do Asaas, Pix e inscrição pública.

## Limites da validação

A suíte não executa uma cobrança real no Asaas, pois as credenciais de Produção e um cartão real não estão disponíveis no ambiente de validação. A homologação final deve confirmar o webhook e a alteração do valor da assinatura após o primeiro pagamento de um cupom `first_charge`.
