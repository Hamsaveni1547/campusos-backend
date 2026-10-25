# M12 — Staff records (API notes)

## Designations [read: any member · write: hr.designations.manage]
`GET /designations` (`filter[category]` teaching\|non_teaching, `filter[status]`), `POST /designations`, `PATCH/DELETE /designations/{id}` — `name`, `category`, `rank` (lower = senior), `status`. Delete blocked while staff hold it.

## Staff [hr.employees.view · hr.employees.manage]
| Method | Path | Notes |
|--------|------|-------|
| GET | `/employees` | paginated. `filter[search]` (name/email/code), `filter[department]`, `filter[designation]`, `filter[staff_type]`, `filter[employment_type]`, `filter[status]` (default `active`), `sort=name\|code\|joined_on\|-joined_on` |
| GET | `/employees/{id}` | detail with qualifications; `personal` block only for users with hr.employees.manage |
| POST | `/employees` | `name`, `email`, `mobile`, `send_invite` (default true) + `staff_type`, `employment_type` (permanent\|probation\|contract\|visiting\|guest), `joined_on`, `department_id`, `designation_id`, `reports_to_id`, `employee_code` (generated from settings `hr.employee_code_format` when omitted), `contract_ends_on`, `gender`, `date_of_birth`, `highest_qualification`, `specialization`, `prior_experience_years`, `address`, emergency contact. Creates/links the login account, emails an invitation, and gives the **Faculty** (teaching) or **Non-teaching Staff** role |
| PATCH | `/employees/{id}` | same fields (account name/email are edited via `/users/{user_id}`) |
| POST | `/employees/{id}/status` | `status` active\|on_leave\|resigned\|retired\|terminated, `reason` (required for exits), `effective_on`. Exits remove access to the institution; back to active restores it |
| POST | `/employees/{id}/qualifications` | `degree`, `specialization`, `university`, `year_of_passing`, `grade`, `certificate_file_id` (upload purpose `document`) |
| PATCH/DELETE | `/employee-qualifications/{id}` | |
| GET | `/me/employee-profile` | own record (`NOT_AN_EMPLOYEE` 404 otherwise) |

## Import
`POST /imports { type: "employees", file_id }` with CSV columns `name, email, staff_type, employment_type, joined_on` (+ optional `mobile, employee_code, department_code, designation`). Then poll and commit (see M01 imports).
