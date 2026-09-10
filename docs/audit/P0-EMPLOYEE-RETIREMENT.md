# Employee deletion means retirement

## Scope and evidence

Risk: employee history loss and inactive payroll membership. Previously DELETE
physically deleted employees without salary records and rejected employees with
salary history. The employee status radios were not connected to requests.

## Behavior

DELETE /employees/{employee} now atomically sets is_active=false and records
employee_retire with actor, execution time and unchanged salary balance. Repeating
the request does not create another transition log. Existing delete permission
is required. No schema migration or automatic production data update is needed.

The UI labels this action Cho nghi viec and explains preservation of existing
paysheets, attendance and payments. Employee list/export default to active and
accept is_active=0 or all for history. Attendance hides inactive schedules by
default; include_inactive=1 and the history checkbox reveal retained records.

New paysheets already select active employees, including custom selections;
regression tests enforce this contract. Retirement is effective immediately for
new membership, not a backdated termination-date calculation. Existing draft or
locked paysheets are not rewritten or hidden by changing employee status.
Outstanding pay/advance balances remain payable and are explicitly warned about.
Retirement does not disable the linked login account or infer admin/test status.

## Verification and deployment

Synthetic tests verify physical preservation, repeated requests, atomic audit,
permissions, old payroll/payment/source hashes, all/custom new payroll selection,
employee list/export filters and attendance history visibility. Build the frontend
when deploying. Test retirement on an authorized employee through the normal UI;
do not delete database rows or remove historical payslips to hide them.
