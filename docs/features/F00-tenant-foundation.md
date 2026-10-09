# F00 - Tenant foundation (S1)

Each supermarket is an isolated business in the same product. This foundation precedes login and all shop-data APIs.

## Implemented boundaries
- Plans/businesses are platform metadata. Branches, users, devices and tokens are scoped by business_id.
- ResolveTenant accepts a single active business subdomain under TENANT_DOMAIN, before authentication/binding; only health bypasses tenant resolution. Client-supplied business IDs and forwarded hosts do not choose tenants.
- Scoped TenantContext is reset at request boundaries. Global model scopes return zero rows without a tenant; creation requires context and overwrites business input. Updates/deletes/refreshes of retained models cannot cross tenants, including quiet operations.
- TenantBuilder protects standard/bulk updates and tenant-ID increments. Raw inserts/upserts must use model creation; direct low-level query escapes remain prohibited by AGENTS.md. Composite foreign keys prevent cross-business branch/user/device/token links.
- Stored public IDs are raw ULIDs; routes/Resources use the model's publicId() method and correct prefix. Model serialization hides internal IDs, hashes and encrypted contact fields. Controllers still use Resources and explicit permission checks in later tasks.
- Sanctum Bearer token lookup is tenant scoped. Another business's token, inactive staff and inactive businesses cannot authenticate. No login or device-token issuance endpoint exists yet; S2 completes device binding, permissions and owner 2FA.
- TenantJob carries a businessId and scalar/array data, never Eloquent models or arbitrary objects. Middleware loads an active business before handling and restores the previous context on completion/failure. TenantCache/TenantFiles build tenant-prefixed keys/paths; file traversal is rejected.

## Demo data
The local/testing-only, idempotent DemoSupermarketSeeder creates four baseline plan records and two businesses: demo-alpha and demo-beta, each with a branch, disabled owner and pending device. Random owner credentials are hashed and never printed; there are no demo tokens. No permission/tier mapping is inferred from role_hint or empty plan features. Products are added only in S4 after quantity rules are specified.

The S2 permission slice now also provisions the approved F01 matrix for each demo shop and assigns its disabled owner the owner role. Role assignment does not enable the account or create usable credentials/tokens.

## Acceptance checks
- [x] Real MySQL proves scoped reads/writes, OR queries, foreign-key links and per-business phone/device uniqueness.
- [x] HTTP probe routes prove subdomain validation, scoped prefixed-ID binding and token rejection across businesses.
- [x] Context resets/restores after requests/jobs, including exceptions; jobs cannot carry Eloquent records.
- [x] Same cache/file names remain separate; no-tenant access and traversal fail.
- [x] Every implemented table containing business_id has a model using BelongsToBusiness; application code has no unauthorised global-scope escapes.
- [x] Tests refuse a development database before RefreshDatabase can reset tables; demo seeding is idempotent and produces no usable staff access.

Run `php artisan test` in backend. Redis transport/worker verification remains pending; S1 tests exercise Laravel's sync queue with real MySQL. Remote CI remains unverified until a repository remote is configured.
