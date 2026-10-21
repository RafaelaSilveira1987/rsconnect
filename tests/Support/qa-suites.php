<?php

declare(strict_types=1);

/**
 * Lista explícita de testes críticos: falhas aqui bloqueiam publicação.
 * O conjunto "historical" contém todos os smoke legados, inclusive os que
 * dependem de versões antigas. Nunca converter falhas em sucesso silencioso.
 */
return [
    'critical' => [
        'tests/Regression/booking-invariants.php',
        'tests/Regression/triage-invariants.php',
        'tests/Feature/triage-open-answer-preservation-20261021-smoke.php',
        'tests/Feature/qa-security-current-contract-20261021-smoke.php',
        'tests/Feature/calendar-whatsapp-status-grid-20261016.php',
        'tests/Feature/appointment-new-booking-privacy-20261016.php',
        'tests/Feature/calendar-daily-grid-completeness-20261015.php',
        'tests/Feature/calendar-availability-inquiry-v363628-smoke.php',
        'tests/Feature/agent-age-opening-identity-v36367r2-smoke.php',
        'tests/Feature/calendar-notice-and-demand-integrity-v36422-smoke.php',
        'tests/Feature/agent-runtime-response-contract-v363626-smoke.php',
        'tests/Feature/calendar-published-lookup-v36391-smoke.php',
        'tests/Feature/agent-triage-semantic-fields-oct2026.php',
        'tests/Feature/agent-binary-triage-after-hours-20261020.php',
        'tests/Feature/calendar-reminder-requires-confirmation-20261020.php',
        'tests/Feature/agenda-reservation-safety-phase2-smoke.php',
        'tests/Feature/appointment-operation-policy-phase1-smoke.php',
        'tests/Feature/agent-readiness-phase2-smoke.php',
        'tests/Feature/calendar-published-slot-reconciliation-20261015-smoke.php',
        'tests/Feature/calendar-published-slot-removal-v36460-smoke.php',
        'tests/Feature/calendar-inactive-allocation-missing-method-20261015-smoke.php',
        'tests/Feature/calendar-ambiguity-capacity-v36420-smoke.php',
        'tests/Feature/after-hours-calendar-recovery-smoke.php',
        'tests/Feature/triage-and-hours-consistency-oct2026-smoke.php',
        'tests/Feature/configuration-unification-20261015-smoke.php',
        'tests/Feature/agent-credential-source-readiness-oct2026-smoke.php',
    ],
    'investigate' => [
        'tests/Feature/calendar-whatsapp-status-grid-20261016.php',
        'tests/Feature/appointment-new-booking-privacy-20261016.php',
        'tests/Feature/calendar-daily-grid-completeness-20261015.php',
        'tests/Feature/calendar-availability-inquiry-v363628-smoke.php',
    ],
    'integration' => [
        'tests/Integration/qa-db-readiness.php',
    ],
];
