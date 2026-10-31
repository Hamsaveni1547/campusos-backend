# Deferred work

Everything consciously left for later, by module. Update this list when a module ships or an item is picked up.
Status: **open** (not started), **planned** (assigned to a known later module/phase).

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
| Academic history (results, backlogs, CGPA) | Needs M09 results | planned (M09) |

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
