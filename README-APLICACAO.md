# RS Connect — nome e logo da empresa no painel

Este pacote corrige a identificação visual mostrada no cabeçalho lateral do painel do cliente.

## Resultado esperado

- o nome exibido vem automaticamente de `tenants.name`;
- a logo configurada em White Label aparece ao lado do nome da empresa;
- quando não houver logo, o sistema mostra as iniciais da empresa;
- a imagem personalizada não herda o fundo degradê do símbolo padrão;
- cores, textos, favicon, login e demais elementos continuam padronizados pela RS Connect;
- a tela White Label continua permitindo apenas enviar, substituir ou remover a logo.

## Correções de persistência

- o upload é gravado em `storage/app/white-label/tenant-{ID}`;
- o instalador prepara a pasta e ajusta as permissões para o Apache/PHP;
- a atualização no banco usa transação;
- depois do `UPDATE`, o controller relê `white_label_enabled` e `brand_logo_url`;
- se o banco não confirmar exatamente o valor gravado, a operação é revertida e uma mensagem clara é exibida.

## Arquivos alterados

- `app/Controllers/WhiteLabelController.php`
- `app/Services/BrandingService.php`
- `app/Views/white_label/index.php`
- `app/Views/layouts/app.php`
- `tests/Feature/white-label-company-brand-smoke.php`

Nenhuma migration é necessária.

## Aplicação automática

Extraia o pacote e execute:

```bash
cd /caminho/onde/extraiu/rs-connect-whitelabel-company-brand-v2
bash apply-white-label-company-brand.sh /var/www/html
```

O instalador cria um backup em:

```text
/var/www/html/storage/backups/white-label-company-brand-AAAAMMDD-HHMMSS
```

Em seguida, valida a sintaxe e executa 42 verificações específicas.

## Validação após a aplicação

1. Entre como administrador da RS Connect.
2. Abra **White Label**.
3. Selecione a empresa.
4. Envie a logo e clique em **Salvar logo**.
5. Entre com um usuário da empresa ou atualize a sessão desse cliente.
6. O cabeçalho lateral deverá mostrar o nome cadastrado em Empresas e a logo enviada.

Para confirmar pelo servidor:

```bash
find /var/www/html/storage/app/white-label -type f -maxdepth 3 -ls
php /var/www/html/tests/Feature/white-label-company-brand-smoke.php
```

## Observação de infraestrutura

A pasta `/var/www/html/storage` deve estar em volume persistente no EasyPanel para que imagens sobrevivam a uma recriação completa do container. O instalador corrige permissões, mas não cria automaticamente um volume no painel de hospedagem.
