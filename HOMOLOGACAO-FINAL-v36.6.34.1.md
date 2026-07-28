# Homologação — RS Connect 36.6.34.1

## Login normal

1. Acesse `/login`.
2. Informe credenciais válidas.
3. Confirme redirecionamento para o dashboard ou onboarding.
4. Verifique que a URL não contém `/https://`.

## Formulário expirado

1. Abra a página de login.
2. Faça um redeploy ou mantenha a página aberta até a sessão/token expirar.
3. Envie o formulário antigo.
4. Esperado:
   - aviso de página de login expirada;
   - retorno para `/login`;
   - nenhuma página 404;
   - URL limpa e sem domínio duplicado.

## URL antiga malformada

Abra manualmente:

```text
https://SEU_DOMINIO/https://SEU_DOMINIO/login
```

Esperado: redirecionamento seguro para `/login`.

## Segurança

- URL de outro domínio não pode ser usada como destino de redirect;
- URL iniciada por `//` deve cair no fallback interno;
- logout e demais formulários protegidos por CSRF continuam funcionando.

## Banco

Não há migration nova.
