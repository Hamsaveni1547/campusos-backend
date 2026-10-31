# M03 Admissions — API notes for the frontend

All routes are under `/api/v1`, need a bearer token and the `X-Institution` header.

## Status flow

`draft → submitted ⇄ correction_requested → verified → eligible → (approved, management/NRI only) → offered → offer_accepted → admitted`

Side exits: `ineligible` (below the seat row's minimum %), `rejected`, `withdrawn`, `expired` (offer not accepted in `offer_valid_days`; hourly job `admissions:expire-offers` frees the seat).
Every change is kept in `history` and the applicant gets an in-app + email notification (`admissions.application_status` template).

## Applicant (self-service)

| Call | Notes |
|---|---|
| `GET admissions/cycles/open` | Open cycles with `seats[]` (programme, quota, available, min_percentage, admission_fee) |
| `GET me/applications` · `GET me/applications/{id}` | Own applications only (404 for others) |
| `POST me/applications` | `seat_id`, `category`, `personal{}`, `guardian{}`, `academic{percentage, board, year_of_passing}`, `entrance{exam, rank, allotment_no}`. One active application per cycle |
| `PUT me/applications/{id}` | Same fields (+ `seat_id` to switch programme/quota in the same cycle); only in `draft` / `correction_requested` |
| `POST me/applications/{id}/documents` | `document_type` (from the cycle's `required_documents`), `file_id` (uploaded with `POST files`, purpose `document`). Re-upload replaces and resets to pending |
| `POST me/applications/{id}/submit` | Needs personal + academic details and every required document. Assigns `application_no` |
| `POST me/applications/{id}/accept-offer` | Before `offer_expires_at` |
| `POST me/applications/{id}/withdraw` | `reason?`; also declines an offer and frees the seat |

Use `can_edit` in the response to show or hide the form.

## Cycles and seats (`admissions.cycles.manage`; reads need `admissions.applications.view`)

`GET|POST admission-cycles`, `GET|PATCH|DELETE admission-cycles/{id}` (fields: `academic_year_id, name, opens_on, closes_on, application_fee, offer_valid_days, required_documents[], instructions`),
`POST admission-cycles/{id}/open` (needs seats) · `/close`,
`POST admission-cycles/{id}/seats` (`programme_id, quota: cet|management|nri|lateral|supernumerary, total, min_percentage?, admission_fee?`),
`PATCH|DELETE admission-seats/{id}` (total cannot go below `filled`).

## Admissions desk

| Call | Permission |
|---|---|
| `GET applications` (filters `status` comma list, `cycle`, `programme`, `quota`, `search`; drafts hidden by default) · `GET applications/{id}` · `GET applications/summary?filter[cycle]=` (count per status) | `admissions.applications.view` |
| `POST applications/{id}/documents/{doc}/review` (`verified`, `remarks` required to reject) · `request-correction` (`remarks`) · `complete-verification` | `admissions.applications.verify` |
| `POST applications/{id}/approve` (`remarks?`) | `admissions.management.approve` (institution head) |
| `POST applications/{id}/reject` (`remarks`) | `admissions.applications.process` |
| `POST applications/{id}/offer` — fails `NO_SEATS_LEFT` / `APPROVAL_REQUIRED` | `admissions.seats.allocate` |
| `POST applications/{id}/confirm` (`fee_amount`, `fee_reference`) → `admitted`, `register_no`, Student role | `admissions.confirm` |

The admission fee is recorded by staff (receipt reference); online payment comes with M11.

## Settings group `admissions`

`application_no_format` (default `{INST}/APP/{YYYY}/{SEQ:5}`), `register_no_format` (default `{INST}{YY}{PROG}{SEQ:3}`, numbered per programme and year).

## Error codes

`CYCLE_CLOSED`, `APPLICATION_EXISTS`, `APPLICATION_LOCKED`, `APPLICATION_INCOMPLETE`, `DOCUMENTS_MISSING`, `DOCUMENT_FILE_INVALID`, `SEAT_INVALID`, `SEAT_NOT_IN_CYCLE`, `REMARKS_REQUIRED`, `DOCUMENTS_NOT_VERIFIED`, `APPROVAL_NOT_NEEDED`, `APPROVAL_REQUIRED`, `NO_SEATS_LEFT`, `OFFER_EXPIRED`, `INVALID_STATUS`, `NO_SEATS`, `SEAT_ROW_EXISTS`, `SEATS_BELOW_FILLED`, `PROGRAMME_INVALID`, `IN_USE`.
