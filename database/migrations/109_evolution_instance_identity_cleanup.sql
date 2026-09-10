-- RS Connect 36.30.5
-- Corrige identidades antigas em que códigos HTTP/metadados curtos foram gravados
-- indevidamente como profile_phone/authorized_phone. A confirmação real volta a ser
-- feita em tempo de execução via connectionState + fetchInstances.

UPDATE evolution_instances
SET profile_phone = NULL
WHERE profile_phone IS NOT NULL
  AND TRIM(profile_phone) <> ''
  AND TRIM(profile_phone) NOT REGEXP '^[0-9]{10,15}$';

UPDATE evolution_instances
SET authorized_phone = NULL,
    identity_status = 'unknown',
    identity_mismatch_at = NULL
WHERE authorized_phone IS NOT NULL
  AND TRIM(authorized_phone) <> ''
  AND TRIM(authorized_phone) NOT REGEXP '^[0-9]{10,15}$';

UPDATE evolution_instances
SET identity_status = 'unknown',
    identity_mismatch_at = NULL
WHERE identity_status IN ('verified', 'mismatch')
  AND (
      authorized_phone IS NULL OR TRIM(authorized_phone) = ''
      OR profile_phone IS NULL OR TRIM(profile_phone) = ''
  );
