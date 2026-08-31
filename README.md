## RS Connect 36.26.0

Principais entregas:

- nova organização visual da tela de inscrição pública;
- gestão de cupons promocionais para assinatura;
- desconto percentual ou fixo;
- cupom para primeira cobrança ou todas as mensalidades;
- validade, limites de uso e restrição por forma de pagamento;
- validação do código antes do Checkout Asaas;
- exibição do benefício no portal financeiro do cliente;
- migration `096_public_signup_coupons.sql`.

Consulte `INSTRUCOES-v36.26.0.md`.

## RS Connect 36.25.1

Principais entregas:

- validação autenticada da conexão com o Asaas Sandbox ou Produção;
- checklist de entrada em Produção no cadastro público;
- aviso visual quando a inscrição ainda usa Sandbox;
- portal financeiro ampliado em **Assinatura e uso**;
- período de teste, primeira cobrança, forma de pagamento e situação do gateway;
- histórico de cobranças e último pagamento;
- registro local de `PAYMENT_CREATED` para exibir cobranças abertas.

O cadastro público continua oferecendo cartão recorrente com 7 dias grátis e Pix QR Code opcional.

Consulte `INSTRUCOES-v36.25.1.md`.

## v36.25.2
Correção do cadastro e ativação dos meios de pagamento com credenciais protegidas.
