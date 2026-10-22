# Attendance after group consolidation

Attendance uses the employee's organization at the time of each mark. `HistoricalUserGroup` includes ordinary `user_group_merged` receipts and legacy `group_consolidation_transfer` receipts with subject type `users`. Older receipts containing only a group change retain branch/role fields from earlier organization receipts; explicit null values remain null. Unknown history is not inferred from the current team. New group merges record complete before/after branch, group and role snapshots.

Deploy the backend before repairing existing marks. No migration or frontend release is required.

Preview a bounded repair with explicit employee merge audit IDs (one per employee, up to 100):

```sh
php artisan attendance:repair-group-merge AUDIT_ID [AUDIT_ID...]
```

Review employee IDs, individual UTC cutoffs, event counts and affected days. Apply the same list with `--apply --backup=/absolute/private/directory/new-backup.json`. The parent directory must already exist; use a directory with mode 0700. The command refuses to overwrite a backup and creates the JSON with mode 0600 before changing data.

The repair locks employees in ID order, changes only organization fields on normalized marks between each audited transfer and the run's captured current time, and rebuilds their affected daily summaries. It preserves raw terminal data, timestamps, event classifications, schedule snapshots and all marks before the transfer. Days spanning groups remain unassigned as a whole; ROPs see only their authorized individual marks. Later audited transfers are respected. Missing historical context aborts the entire batch.

Each changed event/day is recorded in `attendance_audit_logs` with action `attendance.group_merge_repaired` and its source merge audit ID. The backup includes the original changed events and affected summaries. Database changes and their audit rows are atomic. A failed transaction may leave the backup for diagnosis; retry with a new filename. Repeating a completed repair is a no-op unless new affected marks arrive or a summary needs repair. The command never grants access to a group or changes employee assignments.

Verify both the newly assigned ROP's matrix/day API and the former group's boundary. Check that pre-transfer events and unrelated employees are unchanged, that all post-transfer marks resolve to their audited organization, and that a repeat preview reports zero events to change.
