> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 01 — Product Overview

## Goal
Replace paper books, Excel and weak legacy tools in Tanzanian SMEs with an affordable, mobile-first system. Launch with **Pharmacy**; prove it with 3-5 paying pharmacies before building another vertical.

## Users (MVP roles)
| Role | Does | Must not |
|---|---|---|
| Owner | Everything, reports, staff, settings | — |
| Pharmacist | Dispense Rx drugs, prescriptions, controlled register, view stock | See financial reports, buying prices |
| Cashier | POS sales, take payments, open/close own till | See buying prices or reports; sell Rx drugs without pharmacist PIN |
| Stockkeeper | Receive stock, adjust stock, manage batches | See sales reports |
| Super Admin (us) | Create clients, plans, feature flags, subscriptions | — (separate admin site) |

## MVP scope (Phases 1-2)
In: phone + PIN login with device approval; products and batches; POS (cash, M-Pesa, split, credit, hold); till open/close; customers and debt; FEFO; expiry alerts; prescriptions; controlled substances register; basic reports; Filament admin (clients, plans, feature flags); M-Pesa STK push.

Out (later): offline selling (Phase 3), NHIF claims, batch recall, WhatsApp automation, forecasting, multi-branch, loyalty, commissions, suppliers/POs UI, Loans/Agro/Supermarket/Restaurant, TRA E-Fiscal (behind a feature flag when added).

## Plans (feature flags + limits)
| Plan | Price/mo (TSh) | Limits / features |
|---|---|---|
| Free | 0 | 1 user, 50 products, basic POS |
| Basic | 60,000 | 2 users, full inventory, FEFO, expiry alerts, prescription logging |
| Professional | 120,000 | 5 users, controlled substances register, analytics (NHIF later) |
| Enterprise | 220,000 | Unlimited users, multi-branch, TRA E-Fiscal, API access (later) |
Limits are enforced by the API (`PLAN_LIMIT_REACHED`), not just hidden in the UI.

## Quality bar
A cashier on a low-end Android phone can complete a cash sale in under 30 seconds. Stock numbers are always right. One client can never see another client's data.
