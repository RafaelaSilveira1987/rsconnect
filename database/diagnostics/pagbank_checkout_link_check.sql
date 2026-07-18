-- ZIP 36.0.1 — diagnóstico dos links PagBank
SELECT
    i.id,
    i.invoice_number,
    i.status,
    i.gateway_provider,
    i.external_payment_id,
    i.external_checkout_url,
    i.external_invoice_url,
    CASE
        WHEN i.external_checkout_url LIKE CONCAT('%', 'rsconnect.rsautomacaodigital.cloud', '%')
            THEN 'LINK_INTERNO_INVALIDO'
        WHEN i.external_checkout_url IS NULL OR i.external_checkout_url = ''
            THEN 'SEM_LINK'
        ELSE 'LINK_EXTERNO'
    END AS diagnostico_link,
    i.payment_link_created_at,
    i.payment_status_checked_at
FROM tenant_invoices i
WHERE i.gateway_provider = 'pagbank'
ORDER BY i.id DESC;
