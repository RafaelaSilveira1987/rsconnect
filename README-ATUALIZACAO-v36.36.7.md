# RS Connect 36.36.9 — Triagem e agenda: dois cenários

Atualização pontual sobre o hotfix anterior. Consulte `docs/HOTFIX-v36.36.9-DOIS-CENARIOS.md` antes de aplicar; não há migration nova.

As correções abrangem apresentação da primeira resposta determinística, demanda curta sincronizada com regras de grupo, continuação restrita de intenção de agenda após a triagem e proteção contra transformar período em horário exato.

**Importante:** a validação local executou testes puros e smoke tests, mas não substitui uma homologação ponta a ponta com a configuração real da empresa e uma conversa de teste no WhatsApp.

---

# RS Connect 36.36.7 — Mobile 0.5.0 + hotfix conversacional r2

O pacote preserva a identidade visual Mobile 0.5.0 e inclui duas correções no atendimento conversacional. Não há migration nova.

## Hotfix conversacional r2

- respostas numéricas curtas, como `30`, são aceitas quando a triagem está aguardando a idade;
- respostas aproximadas, como `mais de 30`, geram uma clarificação sobre a idade exata em vez de repetir a mesma pergunta;
- a primeira resposta automática passa a se identificar pelo nome público configurado do assistente quando a política de saudação estiver ativa;
- saudações locais da primeira resposta seguem a mesma regra de identificação.

## Aplicação

1. Copie os arquivos do patch sobre a instalação atual 36.36.7.
2. Mantenha a migration `118_contact_origin.sql` aplicada.
3. Reinicie a aplicação/PHP se houver OPcache ativo.
4. Homologue uma conversa nova e o fluxo de idade conforme `docs/HOTFIX-v36.36.7-r2.md`.
5. Para o Mobile 0.5.0, confirme que `https://SEU_DOMINIO/mobile-app/ui-0.5.0.css` e `ui-0.5.0.js` respondem HTTP 200.

Não há alteração de banco de dados neste hotfix.
