CREATE TRIGGER training_progress_immutable_update BEFORE UPDATE ON training_progress FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Training progress is append-only';
