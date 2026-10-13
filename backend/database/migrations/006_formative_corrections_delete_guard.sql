CREATE TRIGGER formative_corrections_immutable_delete BEFORE DELETE ON formative_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Academic corrections are append-only';
