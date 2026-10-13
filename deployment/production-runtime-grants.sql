-- Fresh canonical production install only. Maintenance uses separate credentials.
-- Underscores must be escaped: database-level grants use wildcard matching.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'iti_erp'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `multi\_institute\_iti\_erp`.* TO 'iti_erp'@'%';
