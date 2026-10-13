# Students module

`Http/StudentController.php` handles local learner requests. `Application/` records learners and qualifications. `Domain/StudentIdentity.php` checks the preexisting identity-mode and birth-date format. `Infrastructure/StudentRepository.php` scopes reads to the signed-in institute. These operations do not create official admissions or PRNs.
