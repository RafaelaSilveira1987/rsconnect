# RS Connect — ZIP 22 — White label básico

Este pacote adiciona white label básico por empresa.

## Incluído

- Menu Super Admin `White label`.
- Nome da marca por empresa.
- Subtítulo do painel.
- Logo por URL.
- Favicon por URL.
- Ícone textual curto, quando não houver logo.
- Cores primária, secundária e de destaque.
- Título e subtítulo da tela de login.
- E-mail de suporte.
- Domínio personalizado para identificar a marca no login.
- Opção de exibir ou ocultar `Powered by RS Connect`.
- Prévia visual do painel.

## Como usar

1. Entre como Super Admin.
2. Acesse `White label`.
3. Selecione a empresa.
4. Ative o white label.
5. Configure marca, cores, login e domínio.
6. Salve.

## Pré-visualização do login

Você pode testar o login personalizado com:

```text
/login?tenant=slug-da-empresa
```

Se configurar um domínio personalizado, o sistema também tenta identificar o cliente pelo host acessado.

## Observação

Esta etapa não altera DNS automaticamente. Para domínio personalizado funcionar em produção, o domínio do cliente ainda precisa apontar para o servidor/roteamento correto.
