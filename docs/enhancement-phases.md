# Enhancement phases

This plan sorts every open item in `docs/deferred.md`. Each item either goes into an enhancement phase, is replaced by a simpler and reliable alternative, or is dropped with a reason. Phases run in order after testing with real use; AI work (M21) runs alongside.

**Scope update (user, 2026-11-04):** this is a college demo. Build only important, usable and impressive features, in basic versions. No statutory depth (TDS stays a simple deduction), no external services, no deployment-level complexity. The payment gateway is a simulation, and PDFs may use a Laravel PDF library (dompdf).

Rules used:
- No external services (SMS, payment gateways, third-party APIs) and no new heavy libraries.
- Prefer what the browser or the existing stack already does well (print to PDF, CSV opened in Excel, the Laravel scheduler, mail).
- Build only what a college uses every term.

---

## E1 — Roles, scoping and notifications (foundation) — done 2026-11-04

Built: a shared department scope for HODs (leave approvals, mentoring, announcements), teachers posting only to sections they teach, notifications for published results and timetables, weekly attendance-shortage alerts (to the student and mentor), fee due/overdue reminders, scheduled announcements, and a simulated online fee payment. Dropped as not worth the complexity for a demo: two-step leave approval, daily digest, scheduled report emails.

Do this first, because several later items depend on it.

| Item | From | Plan |
|---|---|---|
| Role linkage: faculty ↔ department, class advisor, HOD scoping | Cross-cutting | Use the department on the employee record and the HOD's department-scoped role. Apply it as one shared query scope. |
| HOD scoping of mentoring allocation and at-risk lists | M18 | Uses the shared scope. |
| HOD scoping of reports and dashboard | M20 | Uses the shared scope. |
| Strict announcement scope: teachers to own sections, HODs to own department | M19 | Teachers get sections they teach (from class allocations); HODs get their department. |
| HOD scoping of leave approvals | M14 | Done. Two-step approval dropped. |
| Notifications for events: registration decisions, timetable changes, attendance shortage, marks and results published | Cross-cutting | One notification class per event on the existing notification hub, so institutions can edit the templates. |
| Weekly attendance shortage alerts to student and mentor | M07 | Scheduled command, weekly. |
| Fee instalment reminders | M11 | Scheduled reminder before and after the due date. |
| Scheduled publishing of announcements | M19 | Store a `publish_at` time; a scheduler job publishes when it is due. |
| Daily digest email | M19 | Dropped (not needed for a demo). |
| Scheduled report emails | M20 | Dropped (CSV download is enough). |

## E2 — Imports, attachments and exports — done 2026-11-04

Built: marks sheet download and CSV upload on the marks screen (fills the grid, the teacher saves), library catalogue CSV import (on the shared Imports page), attachments on complaints and announcements (private, visible to whoever may see the record), a private timetable calendar feed for Google or phone calendars, and Excel-friendly CSV downloads. Dropped as not worth it for a demo: question and asset CSV imports, medical-certificate and maintenance-photo uploads, images in questions, saved report filters.

| Item | From | Plan |
|---|---|---|
| Marks import from CSV | M08 | Download a template, fill it, upload it; rows are checked before saving. |
| Question import from CSV | M10 | Same pattern. |
| Library catalogue CSV import | M15 | Same pattern. Replaces the ISBN lookup. |
| Asset import from CSV | M16 | Same pattern. |
| Attachments: complaints, announcements, medical certificates, maintenance photos | M17, M19, M14, M16 | Reuse the existing private file storage, with type and size limits. |
| Images in questions | M10 | Through the file storage. LaTeX is dropped; write maths in plain text or as an image. |
| Excel exports | M20 | CSV opens directly in Excel and is reliable. Add a UTF-8 BOM so ₹ and Kannada names display correctly. No spreadsheet library. |
| iCal export of timetables | M06 | Plain-text `.ics` feed per teacher, student and room; no library needed. |
| Saved report filters | M20 | Saved per user. |

## E3 — Printable documents

**Updated:** generate real PDFs with a Laravel PDF library (dompdf), with no external service. Fallback: print-ready pages styled with `@media print`. The browser's "Save as PDF" gives a clean PDF with no server dependency. Each document also gets a public **verification page** at a signed URL with a short code printed on it, so anyone can confirm it is genuine. This is used instead of a QR library.

| Item | From |
|---|---|
| Offer letter | M03 |
| Hall tickets, grade cards, transcripts | M09 |
| Fee receipts | M11 |
| Payslips, plus emailing a payslip link | M13 |
| Paper download for the exam cell, with the custodian's name and time printed on every page as a watermark, and one-time links | M10 |
| Exam-hall attendance sheets and seating plans | M09 |

## E4 — Academic depth (OBE and exams)

| Item | From | Plan |
|---|---|---|
| Programme outcomes (PO/PSO) and full CO–PO matrix | M05 | Matrix per course, weights 1–3. |
| Question-wise marks with Bloom's level | M08 | Optional per assessment. |
| Course-end survey feeding indirect CO attainment | M17 | Uses survey questions tagged to course outcomes. |
| NBA CO–PO attainment report | M20 | Built from the two items above. |
| NAAC, NIRF and AISHE data extracts | M20 | Read-only CSV extracts of the figures those portals ask for (students, staff qualifications, pass %, placement left blank). The forms are filled on the portals by hand. |
| Lesson plans per session | M05 | Planned against delivered topics. |
| Lab batches and combined-section periods | M06 | Needed before lab-batch attendance. |
| Lab-batch attendance | M07 | |
| Practical and viva batches, practical examiner marks | M09 | |
| Seating plan and room allocation | M09 | Roll-number order across rooms by capacity. |
| Invigilation and evaluator duties | M09 | Duties only; remuneration goes in as a one-off payroll earning. |
| Double valuation and moderation | M09 | A second evaluator is needed when the difference exceeds a configured limit. |
| Grace marks policy | M09 | Settings: maximum per course and per exam cycle. |
| Malpractice cases and withheld results | M09 | Withhold flag on the result, with a reason. |
| Retotalling and answer-script copy requests | M09 | Same flow as revaluation, different type. |
| Mandatory feedback before hall tickets | M17 | Setting that adds an eligibility check. |
| Answer keys released to evaluators after the exam | M10 | Released when the paper's window closes. |
| Reopening a sealed paper | M10 | Exam board, with a reason, recorded in the audit log. |
| Two-person unsealing | M10 | Replaces the split key: a second custodian must approve, which gives the same protection without key-splitting maths. |
| Merit lists with configurable weights | M03 | Weights for entrance and qualifying marks, set in settings. |

## E5 — Operations

| Item | From | Plan |
|---|---|---|
| Late fee rules | M11 | Flat or per-day fee after a grace period, added as a fee line. |
| Refunds with approval | M11 | Request → approve → record payout. |
| Admission and exam fees raised automatically | M11 | Raised from the admission confirmation and exam registration. |
| Payment reconciliation | M11 | Replaces the gateway reconciliation: upload a bank-statement CSV and match it to UPI/NEFT references recorded at the counter. |
| Library reservations queue | M15 | First come, first served; the copy is held for N days. |
| Library fines posted to the fee account | M15 | Optional setting. |
| Barcode scanning | M15 | USB barcode scanners type like a keyboard, so the accession-number field already works with them. Printable label sheets come in E3. |
| Consumables stock with reorder level | M16 | |
| Equipment issue to students and lab no-dues | M16 | |
| Preventive maintenance schedules | M16 | Recurring requests created by the scheduler. |
| Annual stock verification and condemnation | M16 | |
| Simple straight-line depreciation | M16 | Report only. |
| Student leave applications | M14 | Approved leave marks attendance as on-duty or medical. |
| Leave accrual, carry-forward caps and encashment | M14 | |
| Substitute class arrangement | M14 | Suggests free teachers from the timetable. |
| Loans and advances with EMI | M13 | EMI added as a monthly deduction. |
| Salary revisions with effective dates and arrears | M13 | |
| PF, ESI and PT summary reports | M13 | Monthly CSV summaries; the challans are filed on the government portals. |
| Annual TDS summary per employee | M13 | Replaces the full TDS engine and Form 16 (see below). |
| Bank salary payout sheet | M13 | Replaces bank-specific NEFT files: a CSV with account number, IFSC and amount that any bank's bulk upload accepts after mapping columns. |
| Complaint escalation and routing by category | M17 | Escalates to the next level when the deadline passes. |
| Counsellor role and referrals | M18 | |
| Mentor–mentee messages and parent contact log | M18, M19 | Simple threads inside CAMPUS; parent calls logged by the mentor. |
| Timetable: teacher availability, max periods per day, approval step | M06 | Clash warnings and "copy last term" instead of a generator (see below). |
| Room and teacher utilisation reports | M06 | |
| Trend reports (attendance, results, collections over terms) | M20 | Live queries grouped by term; snapshot tables only if they become slow. |

## E6 — Polish

| Item | From |
|---|---|
| Accessibility pass, Lighthouse ≥ 90, cross-browser check, empty-state wording, demo notes | Cross-cutting |

## M21 — AI (already planned)

NLP on feedback and complaints, at-risk prediction, AI summaries of circulars, dashboard insights.

---

## Won't do

| Item | From | Why | Reliable alternative |
|---|---|---|---|
| Real online payment gateway and webhooks | M11 | External service and merchant onboarding | Built in E1: a simulated gateway (CampusPay) where the student picks UPI, card or net banking and chooses success or failure; a success creates a normal receipt |
| SMS, WhatsApp and push notifications | M19 | External paid services | Email and in-app notifications, plus the daily digest (E1) |
| WebSockets (real-time) | M19 | Extra server process to run and monitor | Polling every 30–60 seconds already works |
| QR or geo-fenced self check-in, biometric import | M07 | Easy to fake from a shared screenshot or GPS spoofing; biometric devices need vendor drivers | The teacher marks attendance; students raise corrections |
| Plagiarism check | M08 | Needs an external service and a large document corpus | Teachers review; out of scope |
| ISBN lookup (Open Library) and MARC import | M15 | Depends on a third-party API | CSV catalogue import (E2) |
| Digital library and institutional repository | M15 | A separate product (DSpace, Google Drive) | Link to it from the library page |
| Acquisitions, vendors and periodicals | M15 | Purchasing belongs in accounting software | Record new books when they arrive |
| Full income-tax (TDS) engine with old/new regimes and Form 16 | M13 | Rules change every budget; mistakes carry legal liability | Monthly TDS entered as a deduction, plus an annual TDS summary (E5); Form 16 generated on TRACES |
| Bank-specific NEFT bulk files | M13 | Every bank uses a different format | Generic payout CSV (E5) |
| Expenses and general accounting | M11 | Belongs in accounting software (Tally) | Export fee collections as CSV for the accountant |
| Automatic timetable generation | M06 | A hard optimisation problem; results are rarely accepted without manual changes | Clash checks, teacher availability and copying last term's timetable (E5) |
| Split-key (Shamir) unsealing and IP allow-list | M10 | Complex to operate; campus IP addresses change | Two-person unsealing (E4); release windows, permissions and MFA |
| LaTeX in questions | M10 | Rendering library and editor | Images in questions (E2) |
| Alumni and employer surveys | M17 | They need public, unauthenticated access, which brings spam risk | Shareable Google Forms; can return later with signed links |
| Disciplinary hold | M09 | Needs a discipline module that is not in the plan | The exam cell withholds the result (E4) |

## Already done

| Item | From |
|---|---|
| Demo seed data for every module | Cross-cutting (seeders for BIT) |
