# Reviewed employee dismissal

The existing dismissal preview now includes recipient branch/group names, approved-property workload, an eligible property co-owner (`preferred_user_id`), and property-linked task references (`follows_property_id`). These are advisory inputs to a reviewed plan; the server revalidates every recipient and record when applying it.

Automatic property allocation first assigns selected eligible co-owners, then chooses the lowest approved-property workload, incrementing it for each assignment. Equal loads rotate in a deterministic order seeded by the dismissed employee ID. Other sections balance the current batch. The user may override any assignment before submitting. Filters affect the candidate pool, not existing assignments; allocation replaces only the current section.

An administrator/superadministrator may move active properties to a recipient in another branch/group. Branch directors remain limited to their branch; HR retains ownership-only transfer within each record's group. Cross-group property assignments require an explicit `destination_branch_group_id` on that plan record, matching the locked recipient's current group. Old clients cannot implicitly authorize relocation.

Relocation uses GroupRecordTransfer, including moderation version checks, audit, property-control cards and active linked task propagation. Tasks in the dismissal inventory are revalidated against their resulting group. Clients, leads, deals and bookings keep their existing eligibility scope. Closed history remains unchanged. Dismissal and all transfers run in one transaction; an incomplete, stale, unauthorized or inconsistent plan must roll back and preserve employee access.

Validation: DismissalTransferTest covers relocation, task propagation, historical preservation, explicit destination, workload/co-owner preview and restricted roles, along with the existing atomicity and stale-plan tests. Frontend unit and component tests cover load-aware allocation, co-owner priority, manual overrides, filters, destination submission, failed confirmation and narrow viewports.
