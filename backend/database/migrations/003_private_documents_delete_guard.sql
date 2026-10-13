CREATE TRIGGER private_documents_immutable_delete BEFORE DELETE ON private_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Private documents require audited archival';
