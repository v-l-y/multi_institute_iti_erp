# Admissions infrastructure

`AdmissionRegistryRepository.php` runs institute-scoped registry queries. The existing transactional write services are still in `../Application/` and use the shared `App/Core/InstituteTransaction.php`. No new database tables, official-source rules or portal writes were added.
