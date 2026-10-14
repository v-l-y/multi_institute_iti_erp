CREATE TABLE auth_rate_limits (
 operation_code VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 bucket_start BIGINT UNSIGNED NOT NULL,
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(operation_code,identity_hash,bucket_start),
 INDEX(updated_at),
 CHECK(attempts>=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;