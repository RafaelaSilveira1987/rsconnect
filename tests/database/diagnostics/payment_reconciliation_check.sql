SELECT id, label, provider, environment, status, is_default FROM payment_gateways ORDER BY id;

SELECT i.id, t.name AS empresa, i.invoice_number, i.amount, i.due_date, i.status,
       i.gateway_provider, i.external_payment_id, i.external_status,
       i.external_checkout_url, i.payment_status_checked_at, i.access_released_at
FROM tenant_invoices i
INNER JOIN tenants t ON t.id = i.tenant_id
ORDER BY i.id DESC LIMIT 50;

SELECT event, status, external_id, created_at
FROM payment_gateway_events
ORDER BY id DESC LIMIT 50;
