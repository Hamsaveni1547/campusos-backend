# M13 Payroll — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`.

Each component is a fixed amount per employee or a percentage of BASIC (optional `max_amount` cap). Loss-of-pay days reduce components marked `affected_by_lop` by (days − LOP) ÷ days. Amounts are rounded to whole rupees.

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin, Finance officer | `payroll.*` |
| Institution head | approve runs, reports |
| Staff | own payslips once the month is finalised |

## Components and salaries

- `GET|POST pay-components`, `PATCH pay-components/{id}` `{code, name, kind (earning|deduction), calculation (fixed|percent), percent?, max_amount?, affected_by_lop, position?, status?}` — BASIC must be a fixed earning.
- `GET payroll/salaries?filter[search]=` → working employees with full-month `gross`/`net` (null when no salary).
- `GET employees/{id}/salary` → `components[] {assigned, amount}` and `preview`; `PUT employees/{id}/salary` `{components[] {id, amount?}}` (`BASIC_REQUIRED`, `AMOUNT_REQUIRED`).

## Monthly runs

`draft → computed → approved → finalized`

- `GET payroll-runs`, `POST payroll-runs` `{month: YYYY-MM}` (a payslip for every working employee with a salary; `RUN_EXISTS`), `DELETE payroll-runs/{id}` (before approval).
- `GET payroll-runs/{id}` → `totals`, `by_department`, `can {edit, approve, finalize}`, `payslips[]` (salary register).
- `PUT payslips/{id}/inputs` `{lop_days, adjustments[] {kind, label, amount}}` — puts the run back to draft.
- `POST payroll-runs/{id}/compute` (`NEGATIVE_NET`), `…/approve` (not by who calculated: `SAME_PERSON`), `…/finalize` `{paid_on}`. Approved and finalised runs cannot change (`RUN_LOCKED`).

## Payslips

- `GET payslips/{id}` — payroll staff, or the employee once finalised → `lines[] {code, name, kind, amount}`, `gross`, `deductions`, `net`, `lop_days`.
- `GET me/payslips` → finalised payslips, newest first.
