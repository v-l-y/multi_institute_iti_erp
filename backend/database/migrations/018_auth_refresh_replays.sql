CREATE TABLE auth_refresh_replays (
 old_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 session_id BIGINT UNSIGNED NOT NULL,
 rotated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 FOREIGN KEY(session_id) REFERENCES auth_sessions(id) ON DELETE CASCADE,
 INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;