-- Fresh staging only: runtime identity cannot alter schema or grant privileges.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'iti_staging'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `iti\_erp\_staging`.* TO 'iti_staging'@'%';
