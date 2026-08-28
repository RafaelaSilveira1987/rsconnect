# Diagnóstico — White Label v36.20.15.4

## Problemas encontrados

1. `BrandingService` gravava e resolvia os dados, mas `layouts/app.php` não o utilizava.
2. `layouts/guest.php` e `auth/login.php` continuavam com logo, textos e cores fixos da RS Connect.
3. As classes da prévia (`brand-preview-shell`, `brand-preview-sidebar`, `brand-preview-login`) não possuíam estrutura CSS completa.
4. A imagem da marca podia ocupar dimensões naturais, deixando a prévia desproporcional.
5. O grid da configuração distribuía conteúdo demais em uma largura sem limite operacional.

## Solução

- integração do `BrandingService::forCurrentRequest()` ao painel e ao login;
- preservação da identidade RS para Super Admin;
- aplicação de tokens CSS por tenant;
- favicon e rodapé por empresa;
- enquadramento de imagens com limites fixos e `object-fit: contain`;
- grid com largura máxima e breakpoints responsivos;
- novo teste comportamental estático com 13 verificações.

## Resultado dos testes

- PHP lint: 318 arquivos aprovados;
- JavaScript: aprovado;
- teste v36.20.15.4: 13 aprovações;
- testes White Label anteriores: aprovados;
- ENT-030: 24 aprovações;
- suíte completa: 86 aprovados, 9 falhas históricas, 95 total.
