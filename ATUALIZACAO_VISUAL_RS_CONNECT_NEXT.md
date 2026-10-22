# RS Connect Next — etapa 1 (identidade visual)

**Base preservada:** RS Connect 36.44.5, conforme `manifest.json` do pacote original enviado.
**Tipo de alteração:** camada de apresentação. **Não é uma nova versão funcional do backend.**

## O que mudou

- Login: apresentação em navy/violeta, chamada mais direta, formulário preservado e crédito discreto à RS Digital Lab.
- Navegação: sidebar navy com seleção violeta, logomarca compacta e busca/topo mais discretos.
- Dashboard do cliente: cabeçalho, indicadores, prioridades e cartões com hierarquia visual e contraste consistentes.
- Dashboard RS Admin: mesma identidade, sem alteração de KPIs, métricas ou URLs.
- Conversas: lista, seleção, cabeçalho, histórico e composição de mensagens com tratamento visual mais leve.
- Responsividade: ajustes para telas menores e respeito à preferência de movimento reduzido.
- Identidade de terceiros: as empresas que utilizam logomarca personalizada (`branding.enabled`) não carregam o tema Next; clientes sem logo personalizada recebem o novo visual.

## Arquivos alterados/adicionados

**Alterados**

- `app/Views/layouts/app.php` — liga CSS do novo tema, escopo dashboard/conversas, logo compacta e favicon padrão.
- `app/Views/layouts/guest.php` — liga CSS de login na identidade padrão, preservando logomarca personalizada.
- `app/Views/auth/login.php` — ajusta texto padrão e autoria, preservando os controles.

**Adicionados**

- `public/assets/css/rs-connect-next.css` — CSS isolado e versionado.
- `public/assets/img/rs-connect-next-favicon.png` — ícone da RS Connect derivado do símbolo existente.
- `tests/Feature/rs-connect-next-visual-smoke.php` — validação estática dos pontos críticos.
- Este documento.

**Sem alterações:** `app/Controllers`, `app/Services`, `database`, `routes`, `public/assets/js`, APIs, mobile-app, permissões, webhooks, integrações e formulários.

## Instalação para validação

1. Faça backup da versão atual e das configurações do ambiente.
2. Em **homologação**, atualize os arquivos do pacote completo ou aplique somente os caminhos do ZIP patch mantendo as demais pastas originais.
3. Limpe o cache do navegador (Ctrl+Shift+R). O novo CSS usa `?v=20261022-1`.
4. Confira login, Dashboard cliente, Dashboard RS Admin e Conversas em desktop e Android.
5. Compare um cliente sem logo personalizada (recebe RS Connect Next) e outro com logo personalizada (mantém o estilo anterior).
6. Rode `php tests/Feature/rs-connect-next-visual-smoke.php`.
7. **Somente depois dos testes** avalie promover a alteração a produção.

### Importante sobre banco de dados

Não há migration nova nesta atualização visual. Entretanto, o **pacote-base é a versão 36.44.5**, cuja documentação exige `126_appointment_triage_snapshot.sql`. Se seu ambiente estiver numa versão anterior, siga o procedimento de atualização funcional dessa versão antes de substituir todo o ZIP. **Para testar somente o visual numa instalação já atualizada, use o pacote patch, não faça migração adicional por causa do tema.**

### Rollback

Restaure os 3 arquivos PHP alterados à versão anterior ou recupere o snapshot da implantação. Não há atualização de banco nem alteração de contrato de API nesta etapa. O arquivo CSS pode permanecer no servidor sem ser carregado.

## Checklist de avaliação manual

- [ ] Login: marca legível, e-mail/senha, mostrar senha, Lembrar de mim, demonstração e cadastro comercial.
- [ ] Menu: abrir/recolher, navegação, destaque do item ativo, contadores e logout.
- [ ] Dashboard cliente: cards/atendimento/agenda/atalhos e permissões.
- [ ] Dashboard RS Admin: KPIs, links e ações rápidas.
- [ ] Conversas: filtros, lista, indicador não lido, seleção, resposta, anexo, transferência, pausar IA, assumir atendimento, detalhes e notas.
- [ ] Mobile: sem rolagem horizontal indesejada em 390px; menu, conversa e drawers acessíveis.
- [ ] White-label: login e navegação específicos do cliente sem o novo tema.
- [ ] Console e Network: sem 404 do novo CSS/favicon ou erro JS causado por layout.
