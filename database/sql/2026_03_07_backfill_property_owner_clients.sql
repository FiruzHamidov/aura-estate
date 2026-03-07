-- Backfill existing property owners into clients and link properties.owner_client_id.
-- Preconditions:
-- 1. Tables/columns already exist:
--    - clients
--    - client_types
--    - properties.owner_client_id
-- 2. Seed data for client_types is already present:
--    - slug = 'individual'
--    - slug = 'business_owner'

START TRANSACTION;

SET @now = NOW();
SET @individual_type_id = (
    SELECT id
    FROM client_types
    WHERE slug = 'individual'
    LIMIT 1
);
SET @business_type_id = (
    SELECT id
    FROM client_types
    WHERE slug = 'business_owner'
    LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS tmp_property_owner_clients;
CREATE TEMPORARY TABLE tmp_property_owner_clients (
    property_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    full_name VARCHAR(255) NULL,
    phone VARCHAR(255) NULL,
    phone_normalized VARCHAR(32) NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    responsible_agent_id BIGINT UNSIGNED NULL,
    is_business_client TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO tmp_property_owner_clients (
    property_id,
    full_name,
    phone,
    phone_normalized,
    branch_id,
    created_by,
    responsible_agent_id,
    is_business_client
)
SELECT
    src.id AS property_id,
    NULLIF(TRIM(src.owner_name), '') AS full_name,
    NULLIF(TRIM(src.owner_phone), '') AS phone,
    CASE
        WHEN src.phone_digits = '' THEN NULL
        WHEN src.phone_digits NOT LIKE '992%' AND CHAR_LENGTH(src.phone_digits) = 9 THEN CONCAT('992', src.phone_digits)
        ELSE src.phone_digits
    END AS phone_normalized,
    u.branch_id,
    src.created_by,
    COALESCE(src.agent_id, src.created_by) AS responsible_agent_id,
    IF(src.is_business_owner = 1, 1, 0) AS is_business_client
FROM (
    SELECT
        p.*,
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(TRIM(COALESCE(p.owner_phone, '')), '+', ''),
                                            '-',
                                            ''
                                        ),
                                        ' ',
                                        ''
                                    ),
                                    '(',
                                    ''
                                ),
                                ')',
                                ''
                            ),
                            '.',
                            ''
                        ),
                        '/',
                        ''
                    ),
                    CHAR(9),
                    ''
                ),
                CHAR(10),
                ''
            ),
            CHAR(13),
            ''
        ) AS phone_digits
    FROM properties p
    WHERE p.owner_client_id IS NULL
      AND (
          NULLIF(TRIM(p.owner_name), '') IS NOT NULL
          OR NULLIF(TRIM(p.owner_phone), '') IS NOT NULL
      )
) AS src
LEFT JOIN users u ON u.id = src.created_by;

DELETE FROM tmp_property_owner_clients
WHERE full_name IS NULL
  AND phone_normalized IS NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_existing_clients_by_phone;
CREATE TEMPORARY TABLE tmp_existing_clients_by_phone
SELECT
    c.phone_normalized,
    MIN(c.id) AS client_id
FROM clients c
WHERE c.deleted_at IS NULL
  AND c.phone_normalized IS NOT NULL
GROUP BY c.phone_normalized;

DROP TEMPORARY TABLE IF EXISTS tmp_existing_clients_by_name;
CREATE TEMPORARY TABLE tmp_existing_clients_by_name
SELECT
    c.full_name,
    c.branch_id,
    MIN(c.id) AS client_id
FROM clients c
WHERE c.deleted_at IS NULL
  AND c.full_name IS NOT NULL
GROUP BY c.full_name, c.branch_id;

DROP TEMPORARY TABLE IF EXISTS tmp_new_owner_client_candidates;
CREATE TEMPORARY TABLE tmp_new_owner_client_candidates
SELECT
    CASE
        WHEN src.phone_normalized IS NOT NULL THEN CONCAT('phone:', src.phone_normalized)
        ELSE CONCAT('name:', COALESCE(src.full_name, ''), '|branch:', COALESCE(CAST(src.branch_id AS CHAR), 'null'))
    END AS group_key,
    MIN(src.property_id) AS sample_property_id,
    MAX(src.is_business_client) AS is_business_client
FROM tmp_property_owner_clients src
LEFT JOIN tmp_existing_clients_by_phone cp
    ON src.phone_normalized IS NOT NULL
   AND cp.phone_normalized = src.phone_normalized
LEFT JOIN tmp_existing_clients_by_name cn
    ON src.phone_normalized IS NULL
   AND cn.full_name = src.full_name
   AND (
       cn.branch_id = src.branch_id
       OR (cn.branch_id IS NULL AND src.branch_id IS NULL)
   )
WHERE cp.client_id IS NULL
  AND cn.client_id IS NULL
GROUP BY
    CASE
        WHEN src.phone_normalized IS NOT NULL THEN CONCAT('phone:', src.phone_normalized)
        ELSE CONCAT('name:', COALESCE(src.full_name, ''), '|branch:', COALESCE(CAST(src.branch_id AS CHAR), 'null'))
    END;

INSERT INTO clients (
    full_name,
    phone,
    phone_normalized,
    branch_id,
    created_by,
    responsible_agent_id,
    client_type_id,
    status,
    created_at,
    updated_at
)
SELECT
    COALESCE(src.full_name, CONCAT('Client from property #', src.property_id)) AS full_name,
    src.phone,
    src.phone_normalized,
    src.branch_id,
    src.created_by,
    src.responsible_agent_id,
    CASE
        WHEN new_clients.is_business_client = 1 AND @business_type_id IS NOT NULL THEN @business_type_id
        ELSE @individual_type_id
    END AS client_type_id,
    'active' AS status,
    @now AS created_at,
    @now AS updated_at
FROM tmp_new_owner_client_candidates new_clients
INNER JOIN tmp_property_owner_clients src
    ON src.property_id = new_clients.sample_property_id;

DROP TEMPORARY TABLE IF EXISTS tmp_existing_clients_by_phone;
CREATE TEMPORARY TABLE tmp_existing_clients_by_phone
SELECT
    c.phone_normalized,
    MIN(c.id) AS client_id
FROM clients c
WHERE c.deleted_at IS NULL
  AND c.phone_normalized IS NOT NULL
GROUP BY c.phone_normalized;

DROP TEMPORARY TABLE IF EXISTS tmp_existing_clients_by_name;
CREATE TEMPORARY TABLE tmp_existing_clients_by_name
SELECT
    c.full_name,
    c.branch_id,
    MIN(c.id) AS client_id
FROM clients c
WHERE c.deleted_at IS NULL
  AND c.full_name IS NOT NULL
GROUP BY c.full_name, c.branch_id;

UPDATE properties p
INNER JOIN tmp_property_owner_clients src
    ON src.property_id = p.id
LEFT JOIN tmp_existing_clients_by_phone cp
    ON src.phone_normalized IS NOT NULL
   AND cp.phone_normalized = src.phone_normalized
LEFT JOIN tmp_existing_clients_by_name cn
    ON src.phone_normalized IS NULL
   AND cn.full_name = src.full_name
   AND (
       cn.branch_id = src.branch_id
       OR (cn.branch_id IS NULL AND src.branch_id IS NULL)
   )
SET p.owner_client_id = COALESCE(cp.client_id, cn.client_id)
WHERE p.owner_client_id IS NULL
  AND COALESCE(cp.client_id, cn.client_id) IS NOT NULL;

UPDATE clients c
INNER JOIN properties p
    ON p.owner_client_id = c.id
SET
    c.client_type_id = @individual_type_id,
    c.updated_at = @now
WHERE c.deleted_at IS NULL
  AND c.client_type_id IS NULL
  AND @individual_type_id IS NOT NULL;

UPDATE clients c
INNER JOIN properties p
    ON p.owner_client_id = c.id
SET
    c.client_type_id = @business_type_id,
    c.updated_at = @now
WHERE c.deleted_at IS NULL
  AND p.is_business_owner = 1
  AND @business_type_id IS NOT NULL
  AND (c.client_type_id IS NULL OR c.client_type_id <> @business_type_id);

COMMIT;

-- Verification queries:
SELECT COUNT(*) AS properties_still_without_owner_client
FROM properties
WHERE owner_client_id IS NULL
  AND (
      NULLIF(TRIM(owner_name), '') IS NOT NULL
      OR NULLIF(TRIM(owner_phone), '') IS NOT NULL
  );

SELECT COUNT(*) AS linked_owner_clients
FROM properties
WHERE owner_client_id IS NOT NULL;
