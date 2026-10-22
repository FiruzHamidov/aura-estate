# Attendance history after a group merge

A ROP assigned to the receiving group can read the full attendance history of an active employee whose current membership was established by an audited group merge. This includes events and daily summaries with an old or unknown group/role, daily comments, leave and duty history, matrix totals and exports. The original ownership, timestamps, schedules and saved totals are not rewritten.

Both `user_group_merged` (`subject_type=user`) and legacy `group_consolidation_transfer` (`subject_type=users`) receipts are accepted. The source must exist (including archived groups), differ from the destination, and belong to the receiving branch. The destination must match the employee's current group in a group assigned to the viewer. Existing receipts take effect without a backfill.

A later audited branch/group change ends this inherited access. A normal transfer back does not reactivate an older merge receipt; a subsequent merge creates new provenance. Non-membership edits do not revoke it. Receipt order uses creation time and ID, and future receipts grant nothing. Current employee eligibility and ROP assignments are checked on every query, so dismissal, movement out of the team or assignment revocation takes effect immediately. Ordinary transfers and other employees keep the existing per-record group boundary.

Group and role filters select the receiving team/current role for inherited history. Historical labels and schedules still come from saved facts. This policy only changes attendance reads, not employee management, CRM access or edit permissions.
