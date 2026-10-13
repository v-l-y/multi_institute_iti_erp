# PROJECT GOAL — V1.0 (FROZEN)

## Bihar Private Multi-Institute ITI ERP Management System

**Project Name:** `multi_institute_iti_erp`  
**Project Version:** 1.0  
**Project Type:** Private ITI Institute Management ERP  
**State:** Bihar Only  
**Institute Coverage:** Multiple Private ITI Institutes  
**Training Scheme:** Craftsmen Training Scheme (CTS)  
**Regulatory Alignment:** Applicable DGT, NCVET and Bihar State requirements  
**Project Status:** GOAL FROZEN — Implementation Verification Pending  
**Scope Baseline:** October 2026

---

## 1. PRIMARY PROJECT GOAL

बिहार राज्य के अनेक **Private Industrial Training Institutes (ITIs)** के लिए एक complete, secure, regulatory-aligned और production-ready Enterprise Resource Planning (ERP) Management System विकसित करना।

ERP का उद्देश्य Private ITI की सभी आवश्यक institutional, administrative, academic और training-related activities को एक integrated system में manage करना है।

इसमें निम्न पूरा institute lifecycle शामिल होगा:

**Institute Setup → Trade Unit / ITSU → Seat Matrix → Admission → Enrollment → Batch → Academic Training → Attendance → Assessment → Examination Records → Course Completion → Institute Reporting**

Staff, Fees, Documents, Authentication और Audit आवश्यकतानुसार पूरे lifecycle को support करेंगे।

### Primary Principles

1. Bihar State Only.
2. Private ITI Institutes Only.
3. Multiple institutes supported.
4. Applicable CTS trades supported.
5. Official regulatory requirements के अनुसार institute management.
6. No invented government rules.
7. No unnecessary independent business modules.
8. Complete frontend, backend और database integration.
9. Secure institute-wise data isolation.
10. Production readiness के लिए measurable acceptance criteria.

**ERP किसी regulatory body, government admission portal, examination authority या certificate-issuing authority का replacement नहीं होगा।**

---

## 2. REGULATORY FOUNDATION — STRICT

ERP का complete institutional, regulatory और academic foundation केवल निम्न पाँच स्रोतों पर आधारित रहेगा।

| # | Foundation | Scope |
|---|---|---|
| 1 | ITI Institute | Institute identity, affiliation, infrastructure और institutional records |
| 2 | Regulatory Body | Applicable central और Bihar State regulatory documents, approvals और instructions |
| 3 | Trades | CTS Global Trade, Shift, Trade Unit, ITSU, approved capacity |
| 4 | Syllabus | Official CTS curriculum, learning outcomes, training hours और assessment criteria |
| 5 | Academic Calendar | Official admission sessions, training periods और applicable academic/exam milestones |

### Foundation Rules

- Official requirements को अनुमान से create नहीं किया जाएगा।
- Government rules hardcode करने से पहले authoritative documents verify होंगे।
- हर regulatory business rule का traceable source होना चाहिए।
- Institute administrative workflows regulatory requirements से conflict नहीं करेंगे।
- Source documents की applicability trade, institute, session और effective date के आधार पर जाँची जाएगी।
- Provisional, draft, superseded और final documents की status अलग रखी जाएगी।
- Official sources उपलब्ध न होने पर unsupported government action या calculation को verified नहीं दिखाया जाएगा।
- Security और internal administrative controls लागू हो सकते हैं, लेकिन उन्हें सरकारी नियम बनाकर प्रस्तुत नहीं किया जाएगा।

**Historical terminology:** पुराने NCVT records अपने original reference के साथ preserve होंगे। NCVET terminology current applicable official records के अनुसार इस्तेमाल होगी। किसी पुराने source को केवल नाम बदलकर reinterpret नहीं करेंगे।

---

## 3. OFFICIAL SOURCE DOCUMENT REGISTER

Regulatory alignment को measurable बनाने के लिए ERP में authoritative documents का Source Document Register रहेगा।

यह नया independent module नहीं होगा; existing institute, regulatory और academic data-management architecture का supporting component होगा।

### 3.1 Primary Source Register

| Source | Issuing Authority | ERP Mapping |
|---|---|---|
| ITI Affiliation Norms 2025 और applicable amendments | DGT | Institute affiliation, trade units, sanctioned facilities, approval references |
| CTS Trade Directory और Official Syllabus | DGT | Global Trade, qualification, duration, curriculum, learning outcomes |
| CTS Training Calendar 2026 और applicable revisions | DGT | Academic session, training calendar और milestones |
| Official CTS Seat Matrix और subsequent final records | DGT / applicable issuing authority | Session-specific ITSU seat-matrix mapping |
| Bihar Private ITI-related official instructions | Applicable Bihar department/directorate | Institute administration और applicable state requirements |
| ITICAT prospectus और admission notices, जहाँ लागू हों | BCECEB | Applicable counselling/admission reference |
| Instructor/NIMS instructions, जहाँ लागू हों | DGT | Instructor approval, mapping और regulatory references |
| Institute-specific affiliation/approval documents | Relevant issuing authority | Actual institute, trade/unit affiliation और validity |

### 3.2 Minimum Source Metadata

हर applicable official source के लिए:

- Source document identifier.
- Document title और issuing authority.
- Official URL या verified source file.
- Reference/order number.
- Issue date और effective date.
- Applicable institute, trade, session और year.
- Document status: Draft / Provisional / Final / Superseded, as applicable.
- Verification status और reviewer.
- Original document reference और relevant audit information.

### 3.3 Authoritative Source Rules

1. Final approval data का source संबंधित official order/verified record होगा।
2. Provisional seat matrix को automatically final नहीं मानेंगे।
3. Institute affiliation और trade/unit approval actual institute-specific documents से map होंगे।
4. Academic dates applicable official calendar से आएँगी।
5. Curriculum और assessment criteria applicable official trade syllabus से आएँगे।
6. BCECEB rules केवल उन admissions पर लागू होंगे जिन पर official prospectus या instructions के अनुसार उनकी applicability स्थापित है।
7. Invalid, expired या superseded sources से नई official approval generate नहीं होगी।
8. Missing official evidence को fake या assumed data से replace नहीं करेंगे।

### Primary Official References

- [DGT — Affiliation Norms 2025](https://www.dgt.gov.in/en/node/3975)
- [DGT — CTS Trade Details and Syllabus](https://dgt.gov.in/en/cts-details)
- [DGT — CTS 2026 Training Calendar](https://dgt.gov.in/en/node/4394)
- [DGT — CTS 2026 Provisional Seat Matrix](https://www.dgt.gov.in/en/node/4675)
- [Bihar — Youth, Employment and Skill Development Department](https://state.bihar.gov.in/yesd/CitizenHome.html)
- [BCECEB — ITICAT Notices and Prospectus](https://bceceboard.bihar.gov.in/ITICATIndex.php)

Official notifications और amendments के आधार पर यह register update हो सकता है, लेकिन project का functional scope अपने-आप नहीं बढ़ेगा।

---

## 4. REGULATORY & DATABASE GLOSSARY

| Term | Fixed Meaning |
|---|---|
| Global Trade | Official CTS trade identity का shared master |
| Global Shift | Applicable shift identity का shared master |
| Trade Unit | Institute-specific sanctioned trade unit, Global Trade से linked |
| ITSU | Institute-specific Trade Unit और Global Shift का mapped combination |
| Seat Matrix | Applicable admission session में official evidence के अनुसार ITSU entries की mapping |
| Admission Session | Applicable official admission period/year की identity |
| Academic Session | Applicable training session/calendar period |
| Batch | Institute-specific enrolled trainee cohort linked to the applicable ITSU और academic context |
| Curriculum | Official syllabus और training/assessment requirements |
| Source Document | Authoritative regulatory या institutional evidence record |

### Trade Management Architecture

- Global Trade master एक shared master रहेगा।
- Global Shift master एक shared master रहेगा।
- Separate institute-level Trade या Shift masters नहीं बनेंगे।
- Trade Unit में institute, global trade, unit number, sanctioned capacity और applicable approval details होंगे।
- ITSU में institute, trade unit और global shift mapping होगी।
- Seat Matrix entry applicable admission session और ITSU से linked होगी।
- Sanctioned capacity Trade Unit में authoritative record के अनुसार maintain होगी। Seat Matrix में duplicate capacity field बिना स्पष्ट source requirement नहीं जोड़ा जाएगा।
- Approval/status/validity details केवल उसी entity पर रखे जाएँगे जिसके लिए official evidence लागू होता है।

### Active/Inactive & History Policy

- Global Trade और Trade Unit में applicable active/inactive lifecycle support होगा।
- Replacement होने पर पुराने referenced records सुरक्षित रहेंगे।
- Existing student, batch और admission references टूटने नहीं चाहिए।
- आवश्यक होने पर पुरानी entry deactivate और नई entry create की जा सकती है।
- Official historical records और audit trails preserve होंगे।
- Unnecessary duplicate master और revision tables नहीं बनाए जाएँगे।
- जहाँ अलग admission sessions, fees periods या legal/audit events की वास्तविक history आवश्यक है, वहाँ appropriate historical records रखे जा सकते हैं।

**History preservation अनिवार्य है; अलग version table बनाना अनिवार्य नहीं।**

---

## 5. FIXED FUNCTIONAL MODULES — V1.0

V1.0 में निम्न **12 modules** अनिवार्य हैं।

| # | Module | Required V1 Functionality |
|---|---|---|
| 01 | Institute Management | Institute profile, affiliation, approval records, infrastructure |
| 02 | Trade & ITSU Management | Global Trade, Global Shift, Trade Unit, ITSU, official mapping |
| 03 | Admission Management | Applicable seat matrix, applications, verification, enrollment |
| 04 | Student Management | Student profile, batch allocation, academic history, completion |
| 05 | Staff Management | Principal, Administrator, teaching/non-teaching staff, instructor records |
| 06 | Academic Management | Academic sessions, calendar, batches, curriculum, syllabus, timetable |
| 07 | Training Management | Attendance, lesson plans, demonstrations, practical, applicable OJT, progress |
| 08 | Assessment & Examination | Formative assessment, eligibility, institute exam records, official result references |
| 09 | Fees Management | Fee structure, collection, receipts, dues, refunds, reversals |
| 10 | Document Management | Secure upload, storage, verification, retrieval, controlled access |
| 11 | Authentication & RBAC | Login, recovery, permissions, user identities, institute isolation |
| 12 | Reports & Audit | Institute registers, academic/administrative reports, internal exports, audit history |

### Module Completion Rule

किसी module को केवल API, database table या UI screen बनने पर complete नहीं माना जाएगा।

Required workflow के लिए लागू होने वाले सभी components कार्यशील होने चाहिए:

- Database schema और relationships.
- Backend services और APIs.
- Frontend screens और interactions.
- Input और business validation.
- Authentication, authorization और institute isolation.
- Create, view, update तथा permitted correction operations.
- Records की traceability और required reporting.
- Integration और applicable test verification.

सभी operations हर record पर उपलब्ध होना आवश्यक नहीं है। Immutable, final या officially locked records के लिए केवल authorized correction workflow लागू होगा।

---

## 6. INSTITUTE MANAGEMENT LIFECYCLE

### 6.1 Institute Setup

Private ITI की identity, official institute reference, affiliation और applicable infrastructure records manage होंगे।

### 6.2 Trade Unit & ITSU

Official trade/unit approval और institute-specific shift mapping maintain होगी।

### 6.3 Admission & Enrollment

Applicable official seat-matrix और admission/counselling evidence के आधार पर institute admission records manage होंगे।

ERP स्वयं सरकारी seat allotment जारी नहीं करेगा।

### 6.4 Batch & Academic Setup

Enrolled students को institute, ITSU, academic session, relevant calendar और curriculum से map किया जाएगा।

### 6.5 Academic Training

Official syllabus के अनुसार timetable, lesson planning, practical और training progress manage होंगे।

### 6.6 Attendance

ERP institute की internal attendance maintain करेगा।

Official biometric, NIMS, SIDH या अन्य government attendance requirements केवल लागू और verified source के अनुसार map होंगी।

किसी दूसरे training scheme के attendance rule को CTS Private ITI पर automatically लागू नहीं करेंगे।

### 6.7 Assessment

Applicable syllabus और academic rules के अनुसार formative assessment, evaluation और eligibility records manage होंगे।

### 6.8 Examination Records

Institute परीक्षा से संबंधित administrative records और officially issued results के references maintain कर सकेगा।

ERP स्वयं official result, marksheet या certificate issue नहीं करेगा।

### 6.9 Course Completion

Student training completion, academic history और applicable official outcome references के आधार पर institute record closure होगा।

### 6.10 Institute Reporting

Institute-specific academic, administrative और financial reporting उपलब्ध होगी।

---

## 7. AUTHENTICATION, USER IDENTITIES & RBAC

### 7.1 Fixed User Roles

| Role | Purpose |
|---|---|
| Developer | ERP technical platform administration |
| Principal | Institute-level principal account |
| Administrator | Institute administrative account |
| Instructor | Teaching staff-linked account |
| Student | Student-linked account |

### 7.2 Identity Rules

- Principal और Administrator institute-specific accounts होंगे।
- Teaching और non-teaching personnel staff records में आएँगे।
- Instructor account संबंधित teaching staff identity से linked होगा।
- Student account संबंधित student identity से linked होगा।
- Role identity create होने से कोई permission automatically grant नहीं होगी।
- Authorization default-deny रहेगा।
- Institute users केवल authorized institute information देख सकेंगे।

### 7.3 Developer Access Restrictions

- Developer role को institute के private student/staff records पर default unrestricted access नहीं होगा।
- Platform-level technical access और institute business-data access अलग होंगे।
- Exceptional production-data access के लिए authorized, limited, time-bound और audited procedure चाहिए।
- हर cross-institute privileged access का purpose और audit record maintain होगा।

### 7.4 Student Role Boundary

Student records V1 में mandatory हैं।

यदि Student login provision किया जाता है, तो Student केवल अपने explicitly authorized records access कर सकेगा।

Complete student self-service portal, online payments या अतिरिक्त student services अपने-आप V1 requirement नहीं बनेंगी।

---

## 8. DATA PROTECTION & SECURITY

Student, staff, institute और financial data के लिए secure handling अनिवार्य होगी।

### Mandatory Security Requirements

1. Personal data minimization.
2. Applicable privacy notices और lawful processing requirements.
3. Parent/guardian-related safeguards, जहाँ कानून के अनुसार आवश्यक हों।
4. Password hashing और secure authentication.
5. Strong authorization और institute-wise isolation.
6. Sensitive documents के लिए restricted access.
7. Secure transport और appropriate encryption.
8. Personal data access और administrative changes की auditability.
9. Document upload validation और secure storage.
10. Applicable retention, deletion और recovery policies.
11. Secret management और production security configuration.
12. Incident logging और appropriate response process.

### Aadhaar & Sensitive Identity Documents

- Aadhaar data केवल lawful, necessary और authorized purpose के लिए handle होगा।
- Full Aadhaar number या biometric information default रूप से collect/store नहीं की जाएगी।
- जहाँ legally permitted identity-document storage आवश्यक हो, वहाँ applicable UIDAI masking और security rules follow होंगे।
- Unverified Aadhaar authentication या offline verification integration implement नहीं होगी।

### Legal Alignment

- Digital Personal Data Protection Act, 2023.
- Digital Personal Data Protection Rules, 2025, उनकी applicable commencement dates और subsequent amendments के अनुसार।
- Applicable UIDAI Aadhaar Act/Regulations.
- Other directly applicable information-security obligations.

Privacy obligations को security requirements माना जाएगा; इन्हें ERP के पाँच regulatory/academic foundations के अतिरिक्त कोई नया academic source of truth नहीं माना जाएगा।

---

## 9. DATABASE & APPLICATION ARCHITECTURE

### 9.1 Database

- Multi-institute relational database architecture.
- Shared regulatory/global masters.
- Institute-specific transactional records.
- Strict tenant foreign-key relationships.
- Consistent primary और foreign keys.
- Referential integrity.
- Appropriate unique constraints.
- Historical data preservation.
- Controlled and auditable corrections.
- Safe migrations for populated databases.

### 9.2 Backend

- Clearly separated business modules.
- Secure APIs.
- Centralized authentication और authorization.
- Business validation और error handling.
- Transaction management.
- Source-backed regulatory decisions.
- Institute isolation.
- Reliable logging और audit.

### 9.3 Frontend

- Complete navigation for 12 modules.
- Working forms और data tables.
- View, create, update और permitted correction operations.
- Form validation और useful error messages.
- Permission-aware screens.
- Responsive layouts.
- Accessible interactions.
- No dummy screens in production workflows.

### 9.4 Integration

**Frontend ↔ Backend ↔ Database**

सभी mandatory institute workflows end-to-end functional होने चाहिए।

### 9.5 Historical Records

- Official and institute records preserve होंगे।
- Completed financial और academic events बिना authorized correction के silently overwrite नहीं होंगे।
- Audit में actor, institute, action और आवश्यक change context capture होगा।
- Deactivated masters के historical references सुरक्षित रहेंगे।
- Existing data को बिना validated migration और recovery preparation के destructive तरीके से modify नहीं किया जाएगा।

---

## 10. REPORTS, REGISTERS & EXPORTS

V1 में institute management के लिए आवश्यक reports और internal exports शामिल होंगे।

### Required Coverage

- Institute और affiliation records.
- Trade Unit / ITSU register.
- Admission और enrollment registers.
- Student/batch reports.
- Staff और instructor records.
- Attendance और academic progress reports.
- Assessment/examination reference reports.
- Fee collection, receipts और dues reports.
- Administrative और security audit records.

### Export Boundary

- Internal institute reports और exports समर्थित होंगे।
- Government-compatible export केवल documented official format उपलब्ध होने और verify होने पर certified माना जाएगा।
- Unsupported portal upload, API या data layout को officially compliant घोषित नहीं करेंगे।

---

## 11. STRICT V1.0 EXCLUSIONS

V1 में निम्न शामिल नहीं होंगे:

1. Government ITI institute-management workflows.
2. Bihar के बाहर किसी अन्य State/UT का operational regulatory scope.
3. Government regulator का approval/affiliation portal.
4. Official government seat allotment या seat matrix publication.
5. Government examination conduct/result issuance system.
6. Official NTC या अन्य government certificate issuance.
7. Unsupported SIDH, NIMS, NCVT-MIS या government API integration.
8. Evidence के बिना government-certified exports.
9. Independent Payroll/HRMS module.
10. Hostel Management.
11. Library Management.
12. Placement Management.
13. AI/ML features.
14. Unrelated ERP modules.
15. Unnecessary duplicate master/revision tables.
16. Unverified regulatory calculations और speculative rules.

इन exclusions के बावजूद V1 के तय modules में शामिल आवश्यक staff, fee, document, audit और training features हटाए नहीं जाएँगे।

---

## 12. MODULE-WISE DEFINITION OF DONE

हर module का required acceptance evidence पहले से निर्धारित होगा।

| Module | Mandatory Acceptance |
|---|---|
| Institute | Verified profile, affiliation records, authorized management |
| Trade & ITSU | Correct global/institute relationships, approvals, historical mapping |
| Admission | Verified source-linked seat/session mapping और complete enrollment workflow |
| Student | Complete student record lifecycle और batch/academic linkage |
| Staff | Teaching/non-teaching records, instructor linkage, controlled access |
| Academic | Calendar, syllabus, batches, curriculum mapping और working timetable |
| Training | Attendance, lesson/practical records और applicable progress workflows |
| Assessment | Correct source-backed assessment और eligibility workflow |
| Fees | Validated payments, receipts, dues, controlled reversals/refunds |
| Documents | Secure upload, access, retrieval और verification |
| Auth & RBAC | Enforced permissions, identity linkage, institute isolation |
| Reports & Audit | Accurate institute registers, exports और traceable audit records |

हर row तभी verified मानी जाएगी जब उसका required database, backend, frontend और integration behavior test/acceptance evidence से confirm हो।

---

## 13. PRODUCTION READINESS — FINAL ACCEPTANCE GATES

V1.0 को **Production Ready** केवल तब घोषित किया जाएगा जब निम्न सभी नौ gates PASS हों।

### Gate 1 — Functional Completion

12/12 required functional modules और उनके defined workflows implemented और verified हों।

### Gate 2 — Application Integration

Database, backend, frontend और आवश्यक internal integration end-to-end functional हों।

### Gate 3 — Regulatory Alignment

Applicable official records और rules से traceability verified हो।

Unsupported government claims, guessed calculations या unverified approval data न हो।

### Gate 4 — Release Blockers

**0 unresolved Critical और 0 unresolved High release blockers.**

Medium/Low findings का documented risk disposition होना चाहिए; कोई known material security या data-loss risk बिना resolution/accepted release process के नहीं छोड़ा जाएगा।

### Gate 5 — Security

Authentication, authorization, tenant isolation, financial controls, privacy और sensitive document access verified हों।

### Gate 6 — Testing

सभी required functional, integration, regression और relevant security tests PASS हों।

### Gate 7 — Data Safety

Existing-data migrations, backup, restore, recovery और data-integrity verification सफल हों।

### Gate 8 — Production Infrastructure

HTTPS, secure deployment configuration, secret handling, logging, monitoring और recovery procedures verified हों।

### Gate 9 — Live UAT & Approval

Representative Bihar Private ITI workflows वास्तविक deployment environment में verify किए जाएँ।

Authorized institute users आवश्यक admission-to-completion workflows approve करें।

### Release Rule

**सिर्फ code complete, staging ready, backend tests green या Docker environment working होने को Production Ready नहीं माना जाएगा।**

---

## 14. SCOPE FREEZE POLICY

V1.0 का functional scope 12 modules तक frozen रहेगा।

### Permitted V1 Work

- Existing mandatory workflows complete करना.
- Confirmed functional defects fix करना.
- Security और data-integrity defects fix करना.
- Applicable regulatory alignment correct करना.
- Existing module integration complete करना.
- Production-readiness blockers resolve करना.

### Not Permitted Without Explicit Scope Approval

- New optional modules.
- Unrelated business workflows.
- Unnecessary new architecture.
- Speculative regulatory features.
- Additional independent systems.
- V1 completion के बाद future enhancements को पुराने release blockers बनाना.

नई mandatory legal/regulatory requirement या confirmed security issue मिलने पर उसका documented impact review किया जाएगा।

Optional work V1.1/V2.0 में रखा जाएगा।

**V1.0 की finishing line अनावश्यक रूप से नहीं बदलेगी।**

---

## 15. DEVELOPMENT & TESTING WORKING RULES

यह section project execution policy है, functional scope नहीं।

1. All implementation work frozen V1 goal के अनुसार होगा।
2. हर module का progress clearly track होगा।
3. Progress statuses: Pending → In Progress → Implemented → Verified.
4. हर verified item के लिए relevant code और verification evidence चाहिए।
5. Reviews में actual defects और incomplete frozen requirements identify होंगे।
6. Defects fix होने के बाद affected functionality re-review होगी।
7. Git changes और migrations traceable रहेंगे।
8. Project owner की explicit instruction के बिना automatic CI/testing execute नहीं होगी।
9. Tests required हैं, लेकिन उनका execution authorization अलग रहेगा।
10. Production deployment और Live UAT के लिए project owner तथा authorized institute stakeholders की approval आवश्यक होगी।
11. Independent review स्वीकार होगा, लेकिन हर finding को frozen scope और evidence के विरुद्ध validate किया जाएगा।
12. No unsupported claim of 100% correctness या production readiness.

---

## 16. FINAL DEFINITION OF DONE — V1.0

Project V1.0 तभी complete होगा जब:

**Functional Completion:** 12/12 modules verified.

**Technical Completion:** Database, backend, frontend और integrations verified.

**Regulatory Completion:** Applicable official source alignment verified.

**Security Completion:** No unresolved Critical/High security या isolation blockers.

**Data Safety:** Safe migrations और successful backup/restore verification.

**Production Completion:** Secure deployment और operational verification PASS.

**UAT Completion:** Authorized institute users द्वारा real workflows accepted.

**Release Decision:** All 9 production acceptance gates PASS.

इसके बाद V1.0 को **Production Ready** घोषित किया जा सकता है।

Future optional modules, UI enhancements या independent features V1.1/V2.0 backlog में रहेंगे।

---

## 17. FINAL PROJECT STATEMENT

**Bihar Private Multi-Institute ITI ERP — V1.0**

Build a complete, secure, regulatory-aligned and production-ready ERP Management System exclusively for multiple Private Industrial Training Institutes located in Bihar, India.

The system shall support applicable CTS trades and provide integrated institute administration, trade and ITSU management, admissions, students, staff, academic operations, training, assessments, examination records, fees, documents, authentication, reporting and audit.

All regulatory and academic behavior shall remain traceable to authoritative institute records, applicable regulatory bodies, official CTS trades, syllabi and academic calendars.

The ERP shall not replace any government regulatory, admission, examination or certification authority.

The system shall be considered V1.0 complete only when all frozen functional requirements and defined production acceptance gates have been verified.

---

## 18. PROJECT BASELINE STATUS

| Item | Status |
|---|---|
| Project Goal | FROZEN |
| State Scope | BIHAR ONLY |
| Institute Scope | PRIVATE ITI ONLY |
| Training Scope | APPLICABLE CTS |
| Functional Scope | 12 FIXED MODULES |
| Regulatory Foundation | 5 FIXED FOUNDATIONS |
| Official Source Traceability | REQUIRED |
| Security & Data Protection | REQUIRED |
| Multi-Institute Isolation | REQUIRED |
| Scope Expansion | NOT ALLOWED WITHOUT APPROVAL |
| CI/Automatic Testing | OWNER APPROVAL REQUIRED |
| Implementation Completion | PENDING VERIFICATION |
| Production Readiness | NOT YET VERIFIED |

**FINAL STATUS: V1.0 GOAL FROZEN — IMPLEMENTATION AND RELEASE ACCEPTANCE PENDING.**

**This document is the fixed functional and production-acceptance baseline for `multi_institute_iti_erp` Version 1.0.**