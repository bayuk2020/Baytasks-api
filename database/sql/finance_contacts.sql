CREATE TABLE IF NOT EXISTS finance_contacts (
    id CHAR(36) COLLATE utf8mb4_unicode_ci NOT NULL,
    name VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    type ENUM('person', 'family', 'employee', 'vendor', 'customer', 'other')
        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'person',
    phone VARCHAR(30) COLLATE utf8mb4_unicode_ci NULL,
    notes TEXT COLLATE utf8mb4_unicode_ci NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_finance_contacts_name (name),
    KEY idx_finance_contacts_type (type),
    KEY idx_finance_contacts_type_name (type, name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='People and organizations linked to finance transactions';

SET @has_contact_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'finance_transactions'
      AND COLUMN_NAME = 'contact_id'
);
SET @add_contact_id_sql = IF(
    @has_contact_id = 0,
    'ALTER TABLE finance_transactions ADD COLUMN contact_id CHAR(36) COLLATE utf8mb4_unicode_ci NULL AFTER income_source_id',
    'SELECT 1'
);
PREPARE add_contact_id_statement FROM @add_contact_id_sql;
EXECUTE add_contact_id_statement;
DEALLOCATE PREPARE add_contact_id_statement;

SET @has_contact_index = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'finance_transactions'
      AND INDEX_NAME = 'idx_txns_contact'
);
SET @add_contact_index_sql = IF(
    @has_contact_index = 0,
    'ALTER TABLE finance_transactions ADD KEY idx_txns_contact (contact_id)',
    'SELECT 1'
);
PREPARE add_contact_index_statement FROM @add_contact_index_sql;
EXECUTE add_contact_index_statement;
DEALLOCATE PREPARE add_contact_index_statement;

SET @has_contact_analytics_index = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'finance_transactions'
      AND INDEX_NAME = 'idx_txns_contact_type_date'
);
SET @add_contact_analytics_index_sql = IF(
    @has_contact_analytics_index = 0,
    'ALTER TABLE finance_transactions ADD KEY idx_txns_contact_type_date (contact_id, type, transaction_date)',
    'SELECT 1'
);
PREPARE add_contact_analytics_index_statement FROM @add_contact_analytics_index_sql;
EXECUTE add_contact_analytics_index_statement;
DEALLOCATE PREPARE add_contact_analytics_index_statement;

SET @has_contact_foreign_key = (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'finance_transactions'
      AND CONSTRAINT_NAME = 'fk_finance_transactions_contact'
);
SET @add_contact_foreign_key_sql = IF(
    @has_contact_foreign_key = 0,
    'ALTER TABLE finance_transactions ADD CONSTRAINT fk_finance_transactions_contact FOREIGN KEY (contact_id) REFERENCES finance_contacts (id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE add_contact_foreign_key_statement FROM @add_contact_foreign_key_sql;
EXECUTE add_contact_foreign_key_statement;
DEALLOCATE PREPARE add_contact_foreign_key_statement;
