# RS Connect — instalação e testes

Guia canônico a partir da versão **36.30.7**.

## 1. Requisitos

- PHP 8.2 ou superior (imagem oficial do projeto: PHP 8.3 + Apache);
- extensões `pdo`, `pdo_mysql`, `curl`, `mbstring` e `openssl`;
- MySQL 8.0+ ou MariaDB 10.6+;
- HTTPS em produção;
- acesso à Evolution API quando WhatsApp estiver habilitado;
- armazenamento persistente para `storage/`.

> O banco da RS Connect é MySQL/MariaDB. Não configure PostgreSQL para este projeto.

## 2. Instalação local com Docker Compose

```bash
cp .env.local.example .env
```

Revise as credenciais e então:

```bash
docker compose up --build -d
```

O Compose sobe três serviços:

1. `db` — MySQL 8.4;
2. `migrate` — executa `php bin/migrate.php bootstrap --yes` e termina;
3. `app` — só inicia depois do banco saudável e das migrations concluídas.

A aplicação fica em `http://localhost:8000` e o MySQL local é publicado em `localhost:3307` apenas para diagnóstico/desenvolvimento.

Para conferir:

```bash
docker compose ps
docker compose logs migrate
docker compose logs app
```

## 3. Instalação em VPS / EasyPanel

Use `.env.vps.example` somente como referência. **Não sobrescreva um `.env` de produção já existente.**

No EasyPanel, prefira cadastrar as variáveis no ambiente do serviço. No mínimo configure:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio
APP_KEY=<segredo forte>
DB_HOST=<host mysql>
DB_PORT=3306
DB_DATABASE=<banco>
DB_USERNAME=<usuario>
DB_PASSWORD=<senha>
EVOLUTION_DEFAULT_URL=<url evolution>
EVOLUTION_DEFAULT_API_KEY=<se aplicável>
EVOLUTION_WEBHOOK_TOKEN=<segredo forte>
```

Mantenha `storage/` persistente, especialmente:

```text
storage/conversation-attachments
storage/generated-reports
storage/app/white-label
storage/logs
```

O document root do servidor web deve ser `public/`.

## 4. Banco vazio

Com o banco criado e vazio:

```bash
php bin/check-requirements.php
php bin/migrate.php verify
php bin/migrate.php install --yes
php bin/migrate.php status
```

Em Docker Compose, o serviço `migrate` executa o bootstrap automaticamente.

## 5. Atualização de banco existente

Antes de qualquer atualização, faça backup validado.

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up --dry-run
php bin/migrate.php up
php bin/migrate.php status
```

Nunca reaplique migrations SQL manualmente sem verificar `schema_migrations` e o status do runner.

## 6. Health checks

- `/health/live` — processo web vivo;
- `/health/ready` — aplicação pronta para tráfego;
- `/health/ready/details` — diagnóstico detalhado, protegido para RS Admin.

Exemplo:

```bash
curl -fsS https://seu-dominio/health/live
curl -fsS https://seu-dominio/health/ready
```

## 7. Validação pós-deploy

Execute:

```bash
php bin/check-requirements.php
php bin/migrate.php verify
php bin/migrate.php status
php tests/Feature/infrastructure-installation-v36307-smoke.php
```

Depois valide no navegador:

1. login;
2. dashboard;
3. uma instância WhatsApp conectada;
4. envio e recebimento de uma mensagem;
5. Conversas e Contatos;
6. Assistente/IA;
7. Agenda;
8. Relatórios;
9. portal de cobrança, quando habilitado.

## 8. Build de release

O projeto contém um empacotador de verificação:

```bash
bash build-full-release.sh 36.30.7
```

Ele valida requisitos, manifesto de migrations, JSONs de infraestrutura, smoke test, Docker Compose quando disponível, gera `SHA256SUMS.txt` e cria o ZIP.

## 9. Segurança

Nunca versionar ou enviar no ZIP de release:

- `.env` real;
- chaves OpenAI/Gemini;
- API Key da Evolution;
- tokens n8n;
- credenciais Asaas;
- dumps de produção;
- anexos e relatórios privados;
- logs reais.

Os arquivos `.env.*.example` contêm apenas modelos e placeholders.
