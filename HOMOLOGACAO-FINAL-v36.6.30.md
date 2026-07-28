# Homologação — RS Connect 36.6.30

## Nova conversa

- [ ] Selecionar uma instância.
- [ ] Buscar um contato por nome.
- [ ] Buscar o mesmo contato por parte do telefone.
- [ ] Selecionar o contato e confirmar preenchimento de nome/telefone.
- [ ] Confirmar aviso quando já existir conversa no mesmo canal.
- [ ] Abrir a conversa existente pelo atalho.
- [ ] Iniciar contato realmente novo e confirmar criação normal.

## Horário por dia

Configurar um agente com:

- Seg–Sex: 08:00–17:00
- Sáb: 08:00–12:00
- Dom: fechado

Validar:

- [ ] sexta 16:00 → dentro do horário;
- [ ] sábado 10:00 → dentro do horário;
- [ ] sábado 13:00 → fora do horário;
- [ ] domingo → fora do horário;
- [ ] próxima abertura calculada corretamente.

## Interface

- [ ] checkbox de aceite LGPD alinhado ao texto;
- [ ] botão de fechar do contato alinhado no canto superior direito;
- [ ] cards de consumo sem repetição visual de números;
- [ ] layout responsivo em tela menor.

## IA e franquia

- [ ] resposta IA RS entregue → +1 franquia;
- [ ] resposta IA com credencial própria entregue → +1 interação total, 0 franquia RS;
- [ ] chamada ao provedor com falha → registra chamada/tokens quando disponíveis, 0 franquia;
- [ ] resposta gerada sem entrega → 0 franquia;
- [ ] detalhamento mostra chamadas e tokens separadamente.

## Validações do pacote

- PHP lint: 182 arquivos aprovados.
- JSON: 49 arquivos válidos.
- JavaScript: sintaxe válida.
- Smoke tests: 11 aprovados.

