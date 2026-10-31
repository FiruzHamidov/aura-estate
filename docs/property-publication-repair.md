# Agent 163: reviewed historical publication repair

This change is prepared locally. It does not authorize deployment or execution on
production. The command never runs from a migration, scheduler, HTTP endpoint or
application startup. Running it without `--apply` makes no database changes.

## Confirmed business decision

On 2026-10-31 the user confirmed that all eight old closed statuses are current.
These listings must remain nonpublic. The repair is deliberately limited to:

| Property | `deal_status` and `moderation_status` | `publication_status` |
| --- | --- | --- |
| 9229 | `sold_by_owner` | `archived` |
| 4868 | `sold` | `archived` |
| 5045, 4232, 4231, 4230, 4229, 4225 | `rented` | `archived` |

The command cannot reinterpret these statuses as building types or reopen the
listings. It preserves `status_id`, approved content snapshots, historical event
rows, sale dates, financial amounts, participants, ownership, photos, listing dates
and building fields. Missing sale dates are not filled with the repair date.

Expected effect, if the eight records still match the investigated state: the
approved tab loses eight rows and the public count stays unchanged. Actual totals
must be re-read: other employees may have edited unrelated listings since the
original 84/76 screenshots. Historical reports may reclassify the repaired rows;
this is not a new sale and must not create new commission/KPI credits or CRM events.

## Separate the building field first

The legacy form sent a building type through `status_id`, which also references
`property_statuses`. The additive migration adds nullable `building_type_id` and a
server-owned `building_type_resolved_at`; it does not copy or classify old values.
The building relation uses the dedicated field. Existing display data can be
returned as `building_type_source=legacy_unverified`, never as a verified new ID.
The new form requires an explicit selection to confirm it; leaving it unselected
does not change an old record. Clearing a confirmed type does not restore the old
fallback. Historical closed status checks remain in effect globally.
Cancelling/rejecting a proposed building change restores both the approved type
and its confirmation marker. Older snapshots restore an unresolved empty type;
they cannot retain a proposal that was never approved.

New clients write `building_type_id`. Old clients may echo an unchanged `status_id`
when editing; the server ignores that echo after locking the current row. The
genuine `available` creation default remains compatible. Other new/changed legacy
values receive a readable 422 requiring the updated form, rather than being
silently interpreted as a sale or building type. Account for old mobile/API clients
in release preparation. External-request conversion follows the same contract.

Release requires the additive schema and backend before the updated frontend is
used. Old clients choosing ambiguous legacy IDs must update. A code rollback keeps
the additive columns and private repair receipts; migration `down()` intentionally
refuses to delete them. Never roll back an entire production database for this task.

## Review and preview (after separate release authorization)

Use an active user with moderation access to all requested records as `REVIEWER_ID`.
Files contain private CRM/history data. Store them under a restricted directory
outside the public web root; never commit them or attach them to a public issue.
The writer creates a new file with mode 0600 and refuses to overwrite a file.

```sh
install -d -m 700 storage/app/private/publication-repair
php artisan properties:reconcile-publication --actor=REVIEWER_ID \
  --export=/ABSOLUTE/PRIVATE/PATH/review.json
```

Export captures the current property and related histories, cases, duplicate
decisions, promotions, photos and sale participants, plus a fingerprint per record.
All entries initially have `decision: "hold"`. Reconcile this fresh evidence with
the confirmed table above. Set `reviewed_by` to the reviewer ID and, for each
reviewed row, set `decision: "confirm_closed"`, `reason`, and `evidence`. The target
deal statuses are prefilled and cannot be changed to a different incident decision.
Leave disputed/missing rows on hold. Do not fabricate fingerprints or dates.

```sh
php artisan properties:reconcile-publication --actor=REVIEWER_ID \
  --manifest=/ABSOLUTE/PRIVATE/PATH/review.json
```

Preview shows intended field changes, public visibility, existing/unknown sale
dates and count deltas. Review relevant historical report totals separately before
application. Active/rejected blocking moderation and confirmed duplicates are
skipped, as are records changed since export. Inspect every row and the exit code.

## Explicit application and verification

Only after separate authorization to change production data:

```sh
php artisan properties:reconcile-publication --actor=REVIEWER_ID \
  --manifest=/ABSOLUTE/PRIVATE/PATH/review.json --apply \
  --backup=/ABSOLUTE/PRIVATE/PATH/before-apply.json
```

The backup must be written and flushed before changes. Each record uses the shared
actor/property lock order and its own transaction. The command rechecks permissions,
the property/related-state fingerprint and moderation blockers under locks. A
technical `publication_state_reconciled` log, moderation event, status-history entry
and private receipt are committed atomically with the correction. Observers that
would create new sale/CRM/notification events are not invoked. The version increases
and `updated_at` records the correction; `listed_at`/`listing_updated_at` do not move.

If any audit write fails, the failed record rolls back. Earlier completed records
retain their receipts. Rerunning the same reviewed manifest with a new backup path
is idempotent; changing an applied manifest under the same batch ID is rejected.
This also applies to partially completed batches: changing a held entry requires
a new reviewed batch. An immutable batch row serializes competing apply/rollback
runs before actor/property locks. Once any receipt is rolled back, that batch can
no longer apply other rows.

After application, read both the approved list and the public agent catalogue with
the same ownership/filter scope, and the eight individual cards. Confirm all eight
remain nonpublic, old logs/participants/financial values still exist, expected new
audit entries exist, and no unrelated records changed. Allow public page caches to
expire according to their configured TTL; no global cache flush is needed.

## Guarded rollback

```sh
php artisan properties:reconcile-publication --actor=REVIEWER_ID --rollback=BATCH_UUID
php artisan properties:reconcile-publication --actor=REVIEWER_ID --rollback=BATCH_UUID \
  --apply --backup=/ABSOLUTE/PRIVATE/PATH/before-rollback.json
```

Rollback is also preview-only by default. It restores only fields changed by this
repair when the complete current fingerprint still matches the post-repair state.
Later edits/history cause a skip and manual review. Versions increase; original
history, repair logs and receipts remain, with an added `publication_repair_rolled_back`
event. It never restores/deletes the whole database, removes history or reapplies an
already rolled-back batch.

## Local validation

Feature tests use isolated in-memory SQLite, including preserved histories and
financial fields, stale data, review/duplicate blockers, audit failure rollback,
partial batch immutability, repeated application, guarded rollback, and old/new
building snapshots. Rehearse on a sanitized copy using the production database
engine before running the schema change and repair there; no production rehearsal
or data changes were performed as part of this implementation.

The related property/conversion suite passes. A wider external-request suite also
exposed two existing failures reproduced on the unchanged backend base `037008c9`:
`test_rop_cannot_use_unadapted_external_agent_management_or_media` (fixture lacks
`rop_branch_groups`) and `test_empty_draft_cannot_be_submitted_until_required_fields_exist`
(validation-message expectation). These unrelated tests were not weakened or
changed. The new building-type conversion scenarios pass.
