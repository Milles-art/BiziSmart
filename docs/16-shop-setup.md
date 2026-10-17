# Fresh Choice shop setup — non-fiscal software preparation

D099 directs work back to BiziSmart software. TRA integration remains disabled; the owner's reported grace period is recorded in decisions, without legal verification or invented dates. Postman installation and provider submissions remain stopped.

**User direction2026-10-16:** leave products, buying costs and opening-stock configuration for their training session with the owner. Keep the current catalogue/stock untouched. The preparation and requirements below are reference for that future session, not an instruction to import now or repeatedly ask for records.

## Owner cannot open a till: missing branch (D103)

Current hosted inspection on2026-10-16 confirms the corrected owner setup service is already installed and Fresh Choice's owner already has a branch assignment. The guarded repair command reported a no-op. Do not reinstall or apply the old hotfix merely because an earlier handoff says pending. Owner sign-in/open-till acceptance still needs the actual operator; use the real counted opening float.

New owner setup assigns the only active shop branch automatically. Existing Fresh Choice onboarding predated this fix. The following inspection/repair instructions are for a genuinely unassigned existing account only; no migration, dependency update, APP_KEY change or frontend replacement is needed on this already-corrected host.

Inspect from the private backend directory:

```sh
php artisan bizismart:owner-branch-repair freshchoice
```

Only when it prints Ready with the intended Main branch, run:

```sh
php artisan bizismart:owner-branch-repair freshchoice --apply
```

The command refuses missing/inactive shops, ambiguous active owners/branches and an open owner till; existing assignments stay untouched. Apply uses the existing staff update service and audit, and revokes old owner sessions/challenges/approvals/offline leases. After an actual repair, sign in again with the same PIN and authenticator, then open Tills & Cash. Enter the actual cash counted for the opening float. This command does not open a till or change stock/money. If it refuses, retain the output and investigate rather than bypassing safeguards or recreating the account.

## Already available

The user approved all 247 Fresh Choice source products and their saved selling prices as current on 2026-10-15. Source: `C:/Users/DIGITAL STAR/family-choice/backend/database/catalog/family-choice-products.json`. Do not change the storefront, its orders or operational database to prepare BiziSmart data. Preserve names, source IDs, selling units, price units and price provenance.

Regenerate a review snapshot from the verified source, at the repository root:

```powershell
node infrastructure/local/prepare-shop-catalogue.mjs --source 'C:/Users/DIGITAL STAR/family-choice/backend/database/catalog/family-choice-products.json' --expected-sha256 57587771afce9742c320f23f1f93d05dc94f7a6ba173c4df515078f50646272d --prices-approved-on 2026-10-15
```

Output: ignored `tmp/fresh-choice-catalogue/preparation.json`. This file is a preparation report, not an API payload or completed import. The command performs no database writes, external requests or price calculations. It refuses a changed source hash, duplicate IDs, missing names/units and non-integer prices. If the source changes, review its content and obtain approval for changed prices before supplying the new hash. Do not blindly update the hash to bypass review.

## Information needed for actual product registration

- Select the intended BiziSmart business and an authorized owner/catalogue operator. Never create records in a guessed tenant or test fixture shop.
- Confirm each selling unit and quantity type. A prepacked 1 kg bag is not automatically a weighed product. Weighed products use `kg` and per-kg pricing in BiziSmart; source labels alone do not authorize a conversion.
- Supply recorded buying prices. Product creation currently requires `buying_price`. Leave unknown values missing rather than zero, which would corrupt profit/valuation/margin reporting.
- Confirm expiry tracking. Do not silently select false for products whose expiry handling is not known.
- Collect real packaging barcodes if scanning is required. Barcodes are optional for manual catalogue creation; source IDs/image numbers are not asserted to be physical barcodes.

ProductService already enforces current permissions, plan limits, barcode reservations, tenant scope and price history. Reuse it rather than writing raw tenant tables or bypassing these rules. Saved selling prices remain the approved figures; selecting a base/pack conversion requires an explicit review of the corresponding price basis.

## Opening stock is a separate step

The existing F13 CSV/XLSX tool imports stock against **already registered** active product/pack barcodes. It does not create products. It requires batch number, exact positive quantity, recorded batch cost and any required expiry date, with a 200-row limit. These facts are not supplied by the storefront's "available" flag. Use actual delivery/count records, preserve preview/commit authority and immutable operation keys, and split files at the supported limit. Never fill every product with invented stock to make checkout appear ready.

## Software and pilot work

Current storage evidence (D107): an existing product photo was read from configured R2 storage and its byte count/dimensions/MIME match saved metadata. The real protected image URL rejects unsigned access401/private/no-store. This is read-only evidence; new image upload/removal and signed-in browser acceptance remain separate. No photos or catalogue/stock records were changed for the check.

Keep cash checkout, held carts, refunds, tills, stock receiving/counts/losses, staff permissions and reporting on the approved services. Prepare the exact shop/printer settings and owner invitation; do not reset owner MFA or expose PINs. Test the selected barcode scanner, receipt printer, scale/display and low-end Android device when model/access details are supplied. Existing same-device display and configurable scale-label software are not verified hardware drivers.

The shop is deployed at freshchoice.bizismart.com; current release and live runtime evidence are in NEXT-CONVERSATION. Address/HTTPS and public desktop/phone sign-in are verified. Backups/recovery, capacity, authenticated shop setup, private storage and device acceptance remain separate checks. Windows cashier hardware preparation is in18-windows-till-pilot.md; models and physical tests remain pending. This catalogue preparation itself performs no deployment/import. Selcom and mobile-settled exchanges remain deferred.
