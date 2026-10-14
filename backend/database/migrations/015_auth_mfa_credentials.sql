CREATE TABLE auth_mfa_credentials (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 secret_cipher TEXT NULL,
 pending_cipher TEXT NULL,
 pending_at DATETIME NULL,
 verified_at DATETIME NULL,
 last_counter BIGINT NOT NULL DEFAULT -1,
 FOREIGN KEY(user_id) REFERENCES auth_users(id) ON DELETE CASCADE,
 CHECK ((secret_cipher IS NULL AND verified_at IS NULL) OR (secret_cipher IS NOT NULL AND verified_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;