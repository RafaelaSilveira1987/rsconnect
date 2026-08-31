# RS Connect v36.26.0 — tela reorganizada e cupons de assinatura

## Entregas

- reorganização completa de **Financeiro → Inscrição pública**;
- resumo visual de status, plano, trial e cupons;
- separação das configurações em Disponibilidade, Cobrança e Comercial/Jurídico;
- checklist lateral do Asaas e barra fixa para salvar;
- criação, edição, pausa e reativação de cupons;
- desconto percentual ou valor fixo;
- aplicação somente na primeira cobrança ou em todas as mensalidades;
- restrição para cartão, Pix ou ambos;
- período de validade, limite total, limite por e-mail e valor mínimo;
- validação do cupom na página pública antes do Checkout;
- registro do código e desconto na inscrição e no portal financeiro do cliente;
- restauração automática do valor normal após o primeiro pagamento quando o cupom vale somente para a primeira cobrança.

## Atualização

Faça backup do banco e dos arquivos. Depois substitua o pacote e execute:

```bash
cd /var/www/html
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Migration nova:

```text
096_public_signup_coupons.sql
```

Resultado esperado:

```text
103 aplicada(s), 0 pendente(s), 0 divergente(s)
```

Não execute `baseline`.

## Criar um cupom

Acesse:

```text
Financeiro → Inscrição pública → Cupons de desconto
```

Exemplo de campanha inicial:

```text
Código: BEMVINDO20
Tipo: Percentual
Valor: 20
Duração: Somente na primeira cobrança
Forma de pagamento: Somente cartão
Usos por e-mail: 1
Ativo: Sim
```

### Primeira cobrança

O Checkout é criado com o valor promocional. Depois que o primeiro pagamento for confirmado pelo webhook, o RS Connect atualiza a assinatura no Asaas para o valor normal das próximas mensalidades.

### Todas as mensalidades

O Checkout e a assinatura são criados com o valor promocional, que permanece nas renovações enquanto a assinatura existir.

## Validação recomendada

1. Crie um cupom de teste limitado a um uso.
2. Abra `/signup` em uma janela anônima.
3. Preencha e-mail e selecione cartão.
4. Aplique o código.
5. Confirme o resumo do desconto.
6. Conclua o Checkout no Sandbox.
7. Confirme que a conta foi provisionada uma única vez.
8. Abra **Administração → Assinatura e uso** na conta criada.
9. Confirme que o código, o desconto e a duração aparecem no portal.
10. Para cupom de primeira cobrança, valide após o primeiro pagamento que a mensalidade voltou ao valor normal.

## Observações

- O valor final nunca pode ficar abaixo de R$ 1,00.
- Cupons expirados, pausados, esgotados ou incompatíveis com a forma de pagamento são recusados.
- Checkouts abandonados deixam de reservar o limite depois da expiração.
- A confirmação financeira continua dependendo do webhook autenticado do Asaas.
