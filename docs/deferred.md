# Deferred work

Everything consciously left for later, by module. Update this list when a module ships or an item is picked up.
Status: **open** (not started), **planned** (assigned to a known later module/phase), **done** (cleared; kept for the record).

Last review (2026-11-02): only items with no dependency on a future module and no new library were cleared. Everything else waits for its module (M11 Finance, M14 Leave, M17 Feedback, M21 Analytics, P5 certificates), a notifications layer, the shared PDF service, or the one-time role-linkage and polish pass.

## Cross-cutting

| Item | Why deferred | Status |
|---|---|---|
| Role linkage: faculty ↔ department, class in-charge / advisor, HOD scoping for academics, timetable and attendance | Decided to revisit after the teaching modules (user, 2026-10-31) | open |
| Frontend polish (F6 leftovers): accessibility pass, Lighthouse ≥ 90, cross-browser check, empty-state copy review, demo notes | Postponed to keep building modules | open |
| Demo seed data for admissions, students, curriculum, timetable, attendance, marks | Pages start empty locally | open |
| Notifications for new events (registration decisions, timetable changes, attendance shortage, marks published) | Core flows first | open |

## M03 Admissions

| Item | Why deferred | Status |
|---|---|---|
| Online payment of application/admission fee | Needs a payment gateway (external dependency) — fee recorded manually for now | planned (M11) |
| OCR of uploaded documents and mismatch flags | Needs an OCR/AI service (external) | planned (M21) |
| Merit lists with configurable weights | Plan phases it after the core flow | open |
| Offer letter PDF | Needs the shared PDF service | open |

## M04 Students

| Item | Why deferred | Status |
|---|---|---|
| Certificates (bonafide, study, conduct, TC, migration) with QR verification | Phase P5 in the plan | planned (P5) |
| Transfers (inter-section, inter-institution) | Phase P5 | planned (P5) |
| No-dues clearance before TC | Needs library/finance/lab modules | planned (P5) |
| Academic history (results, backlogs, CGPA) | Done after M09: results card on the student record (`GET students/{id}/academic-history`) | done |

## M05 Curriculum & teaching

| Item | Why deferred | Status |
|---|---|---|
| Programme outcomes (PO/PSO) and full CO-PO mapping matrix | Basic course outcomes and attainment arrive with M08; full OBE matrix later | open |
| Indirect attainment from course-end surveys | Needs M17 feedback | planned (M17) |
| Lesson plans per session | Topic coverage covers the core need | open |

## M06 Timetable

| Item | Why deferred | Status |
|---|---|---|
| Automatic timetable generation / optimisation | Heuristic solver planned later | open |
| Teacher availability and max-periods-per-day constraints | Needed by the generator | open |
| Lab batches and combined-section periods | Plan phases later | open |
| iCal export, room/teacher utilisation reports | Plan phases later | open |
| Approval step before publish | Publish permission covers it for now | open |

## M07 Attendance

| Item | Why deferred | Status |
|---|---|---|
| Self check-in (QR / geo-fenced), biometric import | Optional future in the plan | open |
| Approved student leave auto-marking | Needs M14 Leave | planned (M14) |
| Weekly shortage alerts to student / parent / mentor | Notification work | open |
| Lab-batch attendance | Needs timetable lab batches | open |

## M08 Internal assessment

| Item | Why deferred | Status |
|---|---|---|
| Question-wise marks with Bloom's level | Assessment-level marks and CO tags first | open |
| Marks import from Excel/CSV | Manual sheet entry first | open |
| Plagiarism check for assignments | External service | open |
| Push locked internal marks to examinations | Done in M09: eligibility reads verified or locked internal marks | done |
| At-risk inputs to analytics | Needs M21 | planned (M21) |

## M09 Examinations & results

| Item | Why deferred | Status |
|---|---|---|
| Exam fees and fee-based eligibility | Needs M11 Finance | planned (M11) |
| Room allocation and seating plan, exam-hall attendance sheets | Core exam flow first | open |
| Invigilation, squad and evaluator duties with remuneration | Core exam flow first | open |
| Double / third valuation and moderation | Single valuation first | open |
| Malpractice cases and withheld results | Core exam flow first | open |
| Grace marks policy | Institution rules vary; needs a design pass | open |
| PDF hall tickets, grade cards and transcripts with QR | Printable web pages first, no PDF library added | open |
| Practical / viva batches and practical examiner marks | Theory exams first | open |
| Retotalling and answer-script photocopy requests | Revaluation first | open |
| Disciplinary hold in eligibility | Needs a discipline module | open |

## M10 Question bank & secure papers

| Item | Why deferred | Status |
|---|---|---|
| NLP tagging (Bloom, topic, CO) and near-duplicate detection | Needs M21 AI; exact duplicates are blocked now | planned (M21) |
| AI-drafted questions as suggestions | Needs M21 | planned (M21) |
| Split key (two custodians) to unseal | Single master key first | open |
| Watermarked PDF download and one-time transfer tokens | Needs the shared PDF service | open |
| Images and math (LaTeX) in questions | Plain text first | open |
| Question import from Excel/CSV | Manual entry first | open |
| Reopening a sealed paper by the board, with audit | Sealed papers are final for now | open |
| Answer keys released to evaluators after the exam | Answers are hidden on release for now | open |
| IP allow-list for release | Window and permission checks first | open |

## M11 Fees

| Item | Why deferred | Status |
|---|---|---|
| Online payment gateway (UPI/cards/netbanking) with verified webhooks | External dependency; payments are recorded at the counter | open |
| Refunds with approval and processing | Counter flow first | open |
| Instalment plans and reminders | Needs notifications | open |
| Late fee rules | Simple due dates first | open |
| Bank/gateway reconciliation | Needs the gateway | open |
| PDF receipts with QR | Printable web receipt first, no PDF library | open |
| Admission and exam fees raised automatically from M03/M09 | Fees are raised from structures or as one-off charges for now | open |
| Expenses | Optional in the plan | open |

## M13 Payroll

| Item | Why deferred | Status |
|---|---|---|
| Income tax (TDS) with old/new regime and investment declarations, Form 16 | Statutory detail; enter TDS as a monthly deduction for now | open |
| ESI, PF and PT challan reports | Payroll register first | open |
| Loans and advances with EMI schedules | One-off deductions cover recoveries for now | open |
| Bank transfer (NEFT bulk) file | Needs bank details on staff records | open |
| LOP taken automatically from leave and attendance | Needs M14 Leave | planned (M14) |
| Salary revisions with effective dates and arrears | Salaries are replaced; arrears go in as one-off earnings | open |
| PDF / emailed payslips | Printable web payslip first | open |

## M14 Leave

| Item | Why deferred | Status |
|---|---|---|
| Student leave applications | Students already ask for on-duty/medical attendance corrections | open |
| Accrual, carry-forward caps and encashment | Yearly allotment first | open |
| Multi-step approval (HOD → principal) and HOD department scoping | Waits for the role-linkage pass | open |
| Class arrangement / substitute when faculty are on leave | Timetable adjustments can be made by hand | open |
| Medical certificate upload | Reason text first | open |

## M15 Library

| Item | Why deferred | Status |
|---|---|---|
| ISBN lookup (Open Library) and MARC/CSV import | External service / bulk import | open |
| Reservations / holds queue | Issue and return first | open |
| Fines posted to student fee accounts (M11) | Fines are collected at the library counter | open |
| Barcode labels and scanner-friendly screens | Accession numbers are typed for now | open |
| Digital library and institutional repository | Plan phases it later | open |
| Acquisitions, vendors and periodicals | Plan phases it later | open |

## M16 Labs & assets

| Item | Why deferred | Status |
|---|---|---|
| Consumables stock with reorder alerts | Asset register first | open |
| Equipment issue to students / projects (lab no-dues) | Register and maintenance first | open |
| Preventive maintenance schedules | Fault reporting first | open |
| Annual stock verification and condemnation workflow | Status can be set to condemned by hand | open |
| Depreciation and asset import | Plan phases it later | open |
| Photos on maintenance requests | Text description first | open |

## M17 Feedback & complaints

| Item | Why deferred | Status |
|---|---|---|
| NLP: sentiment, topic clustering, complaint auto-categorisation, summaries | Needs M21 (user, 2026-11-07) | planned (M21) |
| Course-end survey feeding indirect CO attainment (M05/M08) | Surveys first | open |
| Alumni, employer and parent surveys | Students and staff first | open |
| Mandatory feedback before hall tickets | Configurable rule later | open |
| Complaint escalation chain and auto-routing to departments | SLA overdue flag and manual assignment first | open |
| Attachments on complaints | Text first | open |

## M18 Mentoring

| Item | Why deferred | Status |
|---|---|---|
| At-risk prediction from trends (rule-based flags today) | Needs M21 | planned (M21) |
| Mentor–mentee messaging and parent communication log | Notes and meetings first; messaging belongs with M19/M20 | open |
| HOD scoping of allocation and at-risk lists to their own department | Section filter first | open |
| Counsellor role and referral workflow | Confidential-notes permission first | open |

## M19 Communication (announcements)

| Item | Why deferred | Status |
|---|---|---|
| Scheduled publishing (publish at a future time) | Publish now first; needs the scheduler | open |
| Attachments on announcements | Text first | open |
| Strict scope: teachers only to their own sections, HODs only to their department | Limited audience types first | open |
| Daily digest email, push and WebSockets | In-app and email first; polling is enough | open |
| AI summaries of long circulars | Needs M21 | planned (M21) |
| Mentor–mentee messaging, parent communication log (from M18) | Announcements first | open |

## M20 Reports & analytics

| Item | Why deferred | Status |
|---|---|---|
| Excel/PDF exports and background export jobs | CSV of on-screen rows first; reports are small | open |
| Saved filters and scheduled report emails | Catalogue first | open |
| Trend analytics and snapshot tables (attendance, results, collections over years) | Live queries are fast enough at college scale | open |
| Accreditation packs (NAAC, NBA CO-PO, NIRF, AISHE) | Needs CO attainment data from M05/M08 | open |
| Department scoping of reports and dashboard for HODs | Permission-based access first | open |
| AI insights on dashboards | Needs M21 | planned (M21) |
