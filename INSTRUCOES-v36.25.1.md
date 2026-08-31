# RS Connect v36.25.1 — Produção Asaas e portal da assinatura

## Objetivo

Esta versão prepara a validação controlada do cartão em Produção e amplia a tela **Assinatura e uso** para o cliente acompanhar:

- período gratuito e dias restantes;
- primeira cobrança prevista;
- forma de pagamento;
- provedor e ambiente do gateway;
- situação da assinatura;
- último pagamento;
- cobranças abertas, pagas e vencidas;
- histórico e links de pagamento;
- limites e uso do plano.

Não existe migration nova. A migration obrigatória continua sendo:

```text
095_public_signup_pix_qrcode.sql
```

## Atualização

Substitua os arquivos e reinicie/reimplante o serviço RS Connect.

Não é necessário executar `php bin/migrate.php up` quando a v36.25.0 já estiver instalada.

## Configuração recomendada para Produção

### 1. Preserve o gateway Sandbox

Não sobrescreva a configuração usada na homologação. Crie um novo gateway:

```text
Nome: Asaas Produção
Serviço: Asaas
Ambiente: Produção
Situação: Ativo
Método padrão: Cartão de crédito
```

Informe a API Key gerada na conta Asaas de Produção e um token exclusivo para o webhook.

### 2. Webhook no Asaas Produção

Use:

```text
https://rsconnect.rsautomacaodigital.cloud/webhooks/payments/asaas
```

O token configurado no Asaas deve ser o mesmo salvo no campo de segredo/token do webhook do gateway RS Connect.

Mantenha os eventos de Checkout, assinatura e cobranças já utilizados no Sandbox.

### 3. Validar a chave antes do cadastro real

Acesse:

```text
Financeiro → Inscrição pública
```

Selecione `Asaas Produção` e clique em:

```text
Testar conexão com o Asaas
```

O RS Connect realiza uma consulta autenticada e informa se a chave pertence ao ambiente selecionado.

### 4. Primeiro teste real controlado

Antes de divulgar o cadastro:

1. deixe a opção Pix desativada;
2. selecione o gateway Asaas Produção;
3. mantenha a inscrição pública ativa;
4. use e-mail e CPF/CNPJ ainda não cadastrados;
5. conclua o Checkout com um cartão real autorizado;
6. confirme o recebimento dos webhooks;
7. confirme a criação de apenas uma empresa e uma assinatura;
8. entre com o usuário criado e abra `Assinatura e uso`;
9. confirme o status `Período de teste`, o fim do trial e a primeira cobrança prevista.

## Portal financeiro do cliente

Rota:

```text
/subscription
```

Menu:

```text
Administração → Assinatura e uso
```

A tela agora combina os dados locais da assinatura, o vínculo com o gateway Asaas, a sessão de cadastro público e as cobranças recebidas por webhook.

Quando o gateway selecionado for Sandbox, a tela mostra um alerta claro de homologação. Em Produção, o cliente visualiza o meio de pagamento, renovação, primeira cobrança, histórico e situação financeira.

## Sincronização das cobranças

A partir desta versão, o evento `PAYMENT_CREATED` também cria/atualiza uma cobrança local com status aberto. Assim, o cliente consegue visualizar a cobrança antes do pagamento.

Os eventos de confirmação atualizam a cobrança para paga, e eventos de atraso atualizam para vencida.

## Validação

```bash
php tests/Support/run-smoke-tests.php
php bin/migrate.php verify
```
