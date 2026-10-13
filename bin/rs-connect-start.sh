#!/bin/sh
set -eu

# Worker genérico das filas internas. As regras de negócio (se envia, quando envia
# e qual mensagem usar) continuam vindo das configurações de cada empresa no banco.
# Este processo apenas acorda a fila; não fixa horários ou textos de lembrete.
worker_enabled="${RS_NOTIFICATION_WORKER_ENABLED:-true}"
case "$(printf '%s' "$worker_enabled" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|on)
        interval="${RS_NOTIFICATION_WORKER_INTERVAL_SECONDS:-30}"
        case "$interval" in
            ''|*[!0-9]*) interval=30 ;;
        esac
        if [ "$interval" -lt 10 ]; then interval=10; fi
        if [ "$interval" -gt 300 ]; then interval=300; fi

        (
            # Pequeno atraso deixa Apache/DB terminarem a inicialização sem transformar
            # uma indisponibilidade transitória em falha de deploy.
            sleep 5
            reconcile_output="$(php /var/www/html/bin/calendar-client-automation-reconcile.php 500 2>&1)" || {
                printf '[rs-connect][calendar-client-reconcile] %s\n' "$reconcile_output" >&2
            }
            while true; do
                output="$(php /var/www/html/bin/process-notifications.php 100 2>&1)" || {
                    printf '[rs-connect][notification-worker] %s\n' "$output" >&2
                }
                sleep "$interval"
            done
        ) &
        ;;
esac

# Worker nativo da comunicação financeira da plataforma. Ele não define prazo,
# canal ou texto: apenas executa as regras ativas persistidas no banco.
financial_worker_enabled="${RS_FINANCIAL_NOTIFICATION_WORKER_ENABLED:-true}"
case "$(printf '%s' "$financial_worker_enabled" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|on)
        financial_interval="${RS_FINANCIAL_NOTIFICATION_WORKER_INTERVAL_SECONDS:-300}"
        case "$financial_interval" in
            ''|*[!0-9]*) financial_interval=300 ;;
        esac
        if [ "$financial_interval" -lt 60 ]; then financial_interval=60; fi
        if [ "$financial_interval" -gt 3600 ]; then financial_interval=3600; fi

        (
            sleep 15
            while true; do
                output="$(php /var/www/html/bin/process-financial-notifications.php 2>&1)" || {
                    printf '[rs-connect][financial-notification-worker] %s\n' "$output" >&2
                }
                sleep "$financial_interval"
            done
        ) &
        ;;
esac

exec apache2-foreground
