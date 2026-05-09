# Users List Endpoint (`GET /api/user`)

## Query params

- `name` (string, optional)
- `phone` (string, optional)
- `email` (string, optional)
- `branch_id` (integer, optional)
- `branch_group_id` (integer, optional)
- `role` (string, optional)
- `roles[]` (string[], optional)
- `report_agents` (boolean, optional)
- `include_unassigned` (boolean-like, optional, default `0`)
- `status` (`active|inactive|all`, optional)
- `page` (integer, optional)
- `per_page` (integer, optional)

## `include_unassigned` behavior

- Backward compatible default: if `include_unassigned` is absent, behavior is unchanged (`0`).
- Accepted true values: `1`, `"1"`, `true`, `"true"`.
- Accepted false values: `0`, `"0"`, `false`, `"false"`, absent/undefined.
- Invalid values do not break the endpoint: value is treated as `false` and a warning is logged.
- Applies only for roles `rop` and `branch_director`.
- For `rop`/`branch_director`:
  - default scope: `users.branch_id = currentUser.branch_id`
  - if `include_unassigned=1`: `(users.branch_id = currentUser.branch_id OR users.branch_id IS NULL)`
- For `admin`, `superadmin`, `marketing`, and other roles, visibility logic remains unchanged.

## `branch_group_id` interaction (Variant A)

- If `branch_group_id` is provided for `rop`/`branch_director`, unassigned users (`users.branch_id IS NULL`) are excluded even when `include_unassigned=1`.
- Effective logic with group filter:
  - `users.branch_id = currentUser.branch_id`
  - `AND users.branch_group_id = :branch_group_id`

## Counters and pagination

- `total`, `last_page`, `active_count`, `inactive_count` are calculated for the effective filtered scope.
