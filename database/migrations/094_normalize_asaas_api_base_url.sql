-- RS Connect 36.24.1
-- O Asaas usa endpoints oficiais fixos por ambiente. Remove URLs legadas ou
-- internas (como rsconnect.local) que impedem a criação do checkout público.
UPDATE payment_gateways
SET api_base_url = NULL
WHERE provider = 'asaas'
  AND api_base_url IS NOT NULL;
