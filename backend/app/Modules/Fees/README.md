# Fees module

`Http/FeeController.php` handles API validation and authorization; `Application/FeeService.php` retains audited transaction logic; `Domain/Money.php` parses exact monetary values; `Infrastructure/FeePaymentRepository.php` implements institute- and own-student-scoped payment reads. No fee rules or default amounts were introduced.
