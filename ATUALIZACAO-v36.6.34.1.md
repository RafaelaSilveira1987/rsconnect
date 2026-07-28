# RS Connect 36.6.34.1 — Hotfix de login e sessão

## Problema corrigido

Após um deploy ou expiração da sessão, o formulário de login podia falhar no CSRF e usar o `HTTP_REFERER` absoluto como se fosse um caminho relativo. Isso gerava endereços incorretos como:

```text
https://rsconnect.exemplo.com/https://rsconnect.exemplo.com/login
```

A aplicação então exibia 404, mesmo com a rota `/login` existente.

## Correção

- login com token expirado retorna diretamente para `/login`;
- a nova página gera um token CSRF válido;
- URLs absolutas do próprio domínio são convertidas para caminho interno antes do redirecionamento;
- domínios externos e URLs iniciadas por `//` são rejeitados;
- URLs antigas malformadas abertas no navegador são recuperadas automaticamente;
- não altera teste gratuito, vigência, onboarding, permissões ou cobrança.

## Atualização

1. Publicar o pacote 36.6.34.1.
2. Não executar migration.
3. Após o deploy, abrir diretamente:

```text
https://SEU_DOMINIO/login
```

4. Fazer `Ctrl + F5`.
5. Efetuar login normalmente.

## Observação sobre deploy

Se uma página de login estava aberta antes do deploy, o token CSRF daquela página deixa de ser válido porque a sessão do container pode ter sido recriada. O comportamento correto agora é recarregar o login, não abrir uma URL duplicada.
