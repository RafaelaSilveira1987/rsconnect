# RS Connect Next V2 — Correção visual de homologação

**Data:** 22/10/2026  
**Base:** pacote RS Connect Next V1 derivado da versão 36.44.5  
**Escopo:** exclusivamente apresentação de Login, Dashboard RS Admin e cliente, Conversas e navegação lateral.  
**Não altera:** banco de dados, webhooks, autenticação, permissões, controllers, APIs ou JavaScript.

## Por que não removemos `app.css`

A versão base contém aproximadamente 18.160 linhas em `public/assets/css/app.css`, com estilos de inúmeros módulos e componentes de terceiros. Renomear ou remover todo o CSS causaria regressões em telas que não participam desta revisão. Em vez disso, adicionamos `public/assets/css/rs-connect-next-v2.css` após os CSS existentes, com seletores limitados a `body.rs-next-theme` e `body.rs-next-login`. **As personalizações de marcas dos clientes continuam sem carregar os estilos Next.**

A decomposição e limpeza das seções antigas de `app.css` pode ser feita numa fase própria, com cobertura de testes para os demais módulos.

## Problemas dos prints corrigidos

1. **Dashboard:** carimbo técnico ocupando a mesma linha dos botões — agora o cabeçalho usa grade com linha própria para "Dados atualizados em". Detalhes de motor/banco ainda podem ser vistos ao passar o mouse sobre o carimbo.
2. **Dashboard:** botões com hierarquia inconsistente — a ação primária continua violeta e os demais botões recebem estilo secundário.
3. **Conversas:** filtros apertados numa linha — agora os campos têm larguras mínimas e quebram automaticamente.
4. **Conversas:** contadores e botão `+ Nova` cortados no painel lateral — cabeçalho dividido em título e ações com quebra controlada; largura da lista ajustada.
5. **Menu lateral:** barra de rolagem grossa e itens visualmente apertados — rolagem fina, espaçamentos e contrastes uniformes.
6. **Login:** identidade grande demais e formulário baixo na viewport — proporções, margens e controles compactados, mantendo os campos e recursos. Elementos decorativos foram contidos para evitar expansão horizontal.
7. **Responsividade:** ajustes específicos para telas intermediárias e telefones.

## Arquivos para aplicar a V2 sobre a V1

- `public/assets/css/rs-connect-next-v2.css` **novo**.
- `app/Views/layouts/app.php` carrega CSS v2 **após** o tema v1, somente quando não há marca personalizada.
- `app/Views/layouts/guest.php` faz o mesmo para a tela de login.
- `app/Views/dashboard/admin.php` coloca a identificação da atualização em formato resumido, sem informações técnicas visíveis na mesma linha dos botões.
- `tests/Feature/rs-connect-next-v2-layout-smoke.php` **novo**.

Os CSS `app.css` e `rs-connect-next.css` originais são preservados, assim como os eventos e os scripts existentes.

## Como testar

1. **Faça backup** dos três arquivos PHP que o patch substitui.
2. Aplique o `patch` num ambiente de **homologação que já esteja na RS Connect Next V1**. Se a instalação divergiu da V1, compare os três arquivos PHP antes de substituir.
3. Atualize o navegador com `Ctrl + Shift + R`. O novo CSS utiliza `?v=20261022-2`.
4. Confira `Login`, `Visão geral` e `Conversas` em **1366px**, **1024px** e **390px**.
5. No Login, valide senha, lembrar usuário, demonstração e autenticação. No painel, links dos cards, ações rápidas e o menu. Em Conversas, selecione uma empresa, use filtros, abra atendimento e confira envio, transferência, pausar/assumir IA, seleção múltipla e gavetas.
6. Se houver uma empresa com marca personalizada, confira que ela mantém seus estilos originais.
7. Rode `php tests/Feature/rs-connect-next-visual-smoke.php` e `php tests/Feature/rs-connect-next-v2-layout-smoke.php`.
8. Confira o console e a aba Network procurando falha de CSS, fontes ou JavaScript.

## Resultado da verificação neste pacote

- Sintaxe PHP dos três templates alterados: **OK**.
- Teste estático de regressão v1: **8/8 OK**.
- Teste estático de regressão v2: **10/10 OK**.
- Parser CSS v2: **sem erros de estrutura**.
- Navegador headless em *fixtures* isoladas com CSS real: sem rolagem horizontal da página nas larguras 1365px e 390px para as três telas.
- **Limitação:** não houve autenticação, acesso ao banco da VPS ou validação end-to-end com dados reais da sua instância. As fixtures não substituem o teste ao vivo.

## Reversão simples

Restaure as versões anteriores de:
- `app/Views/layouts/app.php`
- `app/Views/layouts/guest.php`
- `app/Views/dashboard/admin.php`

O arquivo `public/assets/css/rs-connect-next-v2.css` pode permanecer no servidor sem ser utilizado. Não há alteração de banco para desfazer.
