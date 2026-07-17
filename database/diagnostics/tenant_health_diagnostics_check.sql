-- Diagnóstico ZIP 34.4 — Saúde do cliente
SELECT t.id, t.name, t.status,
       hs.overall_status, hs.score, hs.ok_count, hs.warning_count, hs.critical_count, hs.checked_at
FROM tenants t
LEFT JOIN tenant_health_snapshots hs ON hs.id = (
    SELECT hs2.id FROM tenant_health_snapshots hs2 WHERE hs2.tenant_id = t.id ORDER BY hs2.id DESC LIMIT 1
)
ORDER BY t.name;

SELECT tenant_id, status, severity, category, title, occurrence_count, first_seen_at, last_seen_at, resolved_at
FROM tenant_health_incidents
ORDER BY FIELD(status,'open','acknowledged','monitoring','resolved'), FIELD(severity,'critical','warning'), last_seen_at DESC;

SELECT tenant_id, category, component_label, status, summary, checked_at
FROM tenant_health_checks
WHERE snapshot_id IN (SELECT MAX(id) FROM tenant_health_snapshots GROUP BY tenant_id)
ORDER BY tenant_id, sort_order, id;
