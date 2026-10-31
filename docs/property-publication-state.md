# Publication state in the employee workspace

Moderation approval does not by itself mean that a listing is visible to visitors.
Historical rows can retain `moderation_status=approved` while a deal, an archive,
an old catalogue status, or a moderation decision prevents public display.

## Read contract

`GET /api/my-properties` adds `publication_visibility` to each returned listing:

```json
{"is_public": false, "reason_codes": ["legacy_rented"]}
```

The same metadata is returned on authenticated property details and moderation
mutation responses. `is_public` uses the existing `Property::publicSearchable`
scope, including blocking cases and confirmed duplicates. Reasons describe
closed deals, legacy closed catalogue statuses, recorded sale dates, unpublished
states, or a moderation block. Clients must handle unknown reasons gracefully.

With `include_publication_summary=1`, the internal list also returns:

```json
{"publication_summary": {"total": 83, "published": 75, "not_published": 8}}
```

These illustrative counts cover the entire filtered, authorized result, regardless
of pagination. Existing ownership, co-owner, branch and other filters still apply.
The frontend requests the summary only for the main approved-list request.
Without visibility metadata, clients show “Одобрено” rather than claim publication.

## Transitions

Closed deals, recorded sale dates, legacy closed catalogue statuses, archives and
deleted listings require the existing explicit return workflow. Attempts to publish
through submission, approval, snapshot restoration or a detour through drafts fail
atomically with HTTP 409 and `code=PROPERTY_REOPEN_REQUIRED`.

Guards check current state inside the existing transaction and row lock, and check
restored snapshots before publication. Editing content or photos on a closed record
preserves its lifecycle. Ordinary drafts and rejections remain submittable.
An independent appeal can reverse its own duplicate archive; it cannot reopen a
closed deal. Existing return permissions, required reason and audit trail remain.

This change requires no data migration or historical reconciliation. Reading the
metadata does not update records. Historical inconsistencies remain visible until
separately reviewed; the public catalogue eligibility rules are unchanged.
