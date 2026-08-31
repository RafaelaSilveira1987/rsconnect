# RS Connect v36.21.0

## Publicação

1. Faça backup do banco e da versão atual.
2. Publique os arquivos desta versão.
3. Valide o pacote:

```bash
php bin/migrate.php verify
php bin/migrate.php status
```

4. Em um banco já atualizado pelo executor de migrations, aplique a nova estrutura:

```bash
php bin/migrate.php up
```

5. Caso o banco existente ainda não possua histórico em `schema_migrations`, use primeiro:

```bash
php bin/migrate.php baseline --through=088 --yes
php bin/migrate.php up
```

6. Limpe o cache do navegador ou recarregue com `Ctrl + F5`.

## Homologação funcional

1. Abra `/login` e clique em **Testar a IA em uma demonstração**.
2. Percorra os caminhos de planos, proposta, negociação, atendimento humano e fechamento.
3. Entre no painel e abra **Comercial**.
4. Ative **Automação do Comercial** inicialmente no modo **Apenas sugerir movimentações**.
5. Mantenha **Análise inteligente econômica** na primeira homologação.
6. Envie mensagens de teste pelo WhatsApp, confira as sugestões e valide os motivos apresentados.
7. Somente depois altere para **Movimentar automaticamente**.

## Migration obrigatória

```text
090_crm_conversation_automation.sql
```

A automação nasce desativada para todas as empresas. O CRM manual permanece funcionando normalmente.
