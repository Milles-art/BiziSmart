> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# F06 — Pharmacy: FEFO, Prescriptions, Controlled Register, Expiry
**Goal:** legal and safe dispensing.
**Reads:** rules §3, §10; schema pharmacy tables; API Pharmacy section.

## Flow
- FEFO is enforced inside `SaleService` (F03); this feature adds prescriptions, the controlled register and expiry alerts.
- Prescription logging: pharmacist enters patient, prescriber, facility, number, date, items, optional photo (R2, encrypted path, signed URL 1h).
- POS: Rx-flagged item prompts "link prescription or pharmacist PIN". Controlled item always needs a prescription.
- Controlled register: append-only list, filter by date/product, printable PDF for inspectors.
- Expiry dashboard: 30/60/90-day buckets, with quantity and value at cost.

## Edge cases
- Duplicate prescription number for the same prescriber -> `VALIDATION_ERROR`.
- Pharmacist PIN override is logged with item, sale and reason.
- Feature flags: `prescriptions`, `controlled_register` (Professional plan) via Pennant.

## Acceptance criteria
- [ ] Controlled-register rows cannot be updated or deleted (test attempts fail).
- [ ] Photo URL expires after 1 hour; another tenant cannot fetch it.
- [ ] Expiry endpoint returns correct buckets for batches expiring in 10, 45, 80 and 200 days.
- [ ] With `prescriptions` disabled, prescription endpoints return `FEATURE_DISABLED`.
