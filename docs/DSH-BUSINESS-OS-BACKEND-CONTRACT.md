# DSH Business OS — Backend Contract

## Purpose

The backend remains the authoritative cloud/API layer for authentication, licensing, tenant isolation, synchronization and business data. The frontend may cache and mutate locally, but it must never use the existence of a local store as permission to access that data.

## Existing backend capabilities to preserve

- Laravel API with Sanctum authentication.
- Tenant/license-aware synchronization through `/sync/push` and `/sync/pull`.
- Shared CRUD resources.
- Business-specific enterprise workflows for Hospital, Mobile Shop, General Store and Traders.
- Role/permission and license concepts.

## Target request context

Every authenticated business session should have a normalized context:

```json
{
  "license_uuid": "...",
  "business_type": "store",
  "branch_id": null,
  "user_id": "...",
  "role": "Admin"
}
```

`business_type` should use canonical keys rather than UI labels. Suggested keys:

- `store`
- `pharmacy`
- `mobile_shop`
- `hospital`
- `traders`
- `restaurant`
- `repair`

Aliases may be accepted at the boundary for compatibility, but internal authorization should use canonical keys.

## Data isolation rule

Tenant/license isolation and business compatibility are separate checks:

1. Is the actor authorized for this license/tenant?
2. Is the requested entity allowed for the actor's business type and role?
3. Does the record belong to the active tenant/branch?
4. Only then read/write/sync the record.

A Store client must not receive Hospital, Mobile or Trader records simply because the authenticated user can access the generic API.

## Sync contract

The existing UUID-based push/pull protocol should remain compatible.

Required properties for every business record participating in sync:

- stable UUID
- license/tenant identity
- business type where applicable
- timestamps/revision metadata
- tombstone support for deletes
- idempotent application of repeated operations

Pull responses should be filtered by tenant and business context before being returned to the client.

## API organization

Prefer shared domain endpoints for shared concepts:

- products
- categories
- brands
- customers
- suppliers
- sales
- purchases
- expenses
- payments
- inventory
- reports

Use business-specific endpoints only when the workflow is genuinely domain-specific:

- Hospital clinical workflows
- Mobile IMEI/warranty workflows
- Trader challan/recovery workflows
- Pharmacy medicine/batch/expiry workflows
- Restaurant KOT/table/kitchen workflows

This avoids creating a separate backend architecture for every business while keeping domain rules explicit.

## Migration safety

Do not remove existing tables or API resources during the initial UI refactor. Introduce the canonical registry and compatibility layer first. Deprecation can happen only after client usage and sync migration tests confirm that no active tenant depends on the legacy path.

## Security finding requiring immediate action

The repository currently contains a tracked `.env` file. Environment secrets must not be committed to Git. The file should be removed from version control and any credentials that were ever stored in it should be rotated. `.env.example` remains the safe template.

## Validation matrix

Before production rollout, verify at minimum:

- Store tenant sees only Store-compatible data.
- Hospital tenant sees only Hospital-compatible data.
- Mobile tenant sees only Mobile-compatible data.
- Trader tenant sees only Trader-compatible data.
- Same user switching/activating a different business profile receives the correct context.
- Offline-created records sync into the correct tenant/business.
- Repeated sync operations do not duplicate records.
- Deleted records propagate as tombstones.
- Role restrictions still apply after sync.
- Branch isolation is enforced when branches are enabled.
