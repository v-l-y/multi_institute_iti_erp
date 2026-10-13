CREATE TRIGGER attendance_corrections_immutable_update BEFORE UPDATE ON attendance_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Academic corrections are append-only';
