# BiziSmart POS — External Dependencies and Blockers

Nothing below may be reported as working until the real credential, device or approval exists. Code boundaries are implemented; behaviour is not faked.

| # | Dependency | State today | Needed to unblock | Reference |
|---|---|---|---|---|
| 1 | Selcom mobile money (M-Pesa/Airtel/Mixx) | Adapter, dispatch journal, callbacks, reconciliation and UI complete; merchant not configured | Selcom merchant account, API keys, signed callback URL, verified settlement reports | `MobilePaymentService`, `docs/LAUNCH-READINESS.md` |
| 2 | TRA / TotalVFD fiscalisation | Discovery document only; feature flag forced off | Approved fiscal provider, technical contract, certificates, regulatory go-live | D099, `docs/features/F22-totalvfd-discovery.md` |
| 3 | Multi-branch stock transfers | Not implemented; deliberately blocked | Owner decision on stock/approval contract (dispatch, transit, partial receipt, discrepancy) | D092 |
| 4 | Automated bank feeds | Manual statement capture only | Bank aggregation API or export integration | D093 |
| 5 | Offline credit and mobile-money sales | Cash-only offline queue | Signed approval/limit contract and provider settlement proof | `docs/07-decisions.md` |
| 6 | Hardware: 80 mm thermal printer | Browser print path exists; device-dependent | Physical printer test on the target device | `ReceiptPrint.tsx` |
| 7 | Hardware: cash drawer | Not implemented | ESC/POS drawer kick from a supported printer bridge | — |
| 8 | Hardware: electronic weighing scale | Label-format decode only | Scale model, serial protocol, physical test | `ScaleLabel.php`, D157 |
| 9 | Remote customer display | Same-device popout only | Second-device pairing protocol | D157 |
| 10 | WhatsApp Business messaging | Send pipeline, report polling, consent records present | Meta Business account, template approvals, number verification | `MessagingService` |
| 11 | SMS (Beem) | Send pipeline + exact report polling present | Beem account and sender ID | `SmsDeliveryReportTest` |
| 12 | Email delivery (password reset/activation) | Tokens and mail pipeline complete | SMTP relay and mailbox for the sending domain | F01 |
| 13 | Object storage (receipt/product photos) | Private authenticated storage implemented | R2/S3 bucket credentials in production | `ProductImageService` |
| 14 | Payment terminals (card) | Not implemented | Terminal hardware and acquirer contract | — |
| 15 | Hosted deployment | Shared-hosting profile implemented | MariaDB runtime, real cron, HTTPS, capacity acceptance | D096, `docs/14-shared-hosting.md` |

## Honest statement for customers

- BiziSmart sells cash-first and records every payment it cannot verify as "recorded, not confirmed".
- Mobile money is available only where the merchant is configured and settlement is confirmed; the system never fabricates a provider success.
- TRA compliance is not implemented; the owner must use an approved fiscal solution until the integration is verified.
- Bank feeds and multi-branch transfers are manual/blocked by design until their contracts are agreed.