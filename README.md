# RS Connect — v36.17.2

A v36.17.2 restaura e fortalece a exibição da foto real dos contatos do WhatsApp na Caixa de Entrada e na base de contatos.

## Principais correções

- renova automaticamente URLs temporárias/expiradas das fotos;
- ao detectar erro de carregamento no navegador, solicita uma nova URL à Evolution;
- mantém a inicial somente quando o contato realmente não possui foto disponível;
- processa eventos `CONTACTS_UPSERT` e `CONTACTS_UPDATE`;
- registra a última verificação para evitar consultas repetitivas;
- mostra a foto também na tela **Contatos**, quando disponível;
- mantém todo o layout de criação de instância da v36.17.1;
- preserva a camada de economia de IA da v36.17.0.

## Atualização obrigatória

Execute:

```sql
database/migrations/078_contact_avatar_refresh.sql
```

Depois valide com:

```sql
database/diagnostics/contact_avatar_refresh_v36.17.2.sql
```

Consulte `INSTRUCOES-v36.17.2.md` para o roteiro completo.
