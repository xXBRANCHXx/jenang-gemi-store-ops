-- Store Ops employee seed rows.
-- Replace the sample PIN hashes before production use.
-- Hash format can be either SHA-256 hex of the PIN/password or a password_hash() value.

INSERT INTO store_ops_employees_v2 (id, display_name, pin_hash, active, created_at, updated_at)
VALUES ('admin', 'Admin', 'ba7e42d060466c149e331452cc58339e64b62a3b61ed953e90f3ec274495f59d', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  updated_at = UTC_TIMESTAMP();

INSERT IGNORE INTO store_ops_employees_v2 (id, display_name, pin_hash, active, created_at, updated_at)
VALUES
  ('employee-a', 'Employee A', 'CHANGE_ME_SHA256_OR_PASSWORD_HASH', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('employee-b', 'Employee B', 'CHANGE_ME_SHA256_OR_PASSWORD_HASH', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP());
