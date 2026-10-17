# Windows till: scanner and receipt-printer pilot

The user confirmed a Windows cashier computer on2026-10-16. Pelsam supplied generic specifications for a USB1D/2D CMOS scanner (QR, EAN-13, UPC, Code128, Code39,5V) and an80mm direct-thermal printer (150–250mm/s, USB/Bluetooth/Ethernet depending on model). These are supplier statements, not an identified model or verified compatibility. They do not describe a weighing scale.

User follow-up: printer brand **Xprinter**, Windows drivers supported, actual ports reported **LAN and USB**. Exact printer model and scanner model are still unknown; supplier has not provided them. Do not hold unrelated software work for these details or repeatedly ask the user to obtain them now. Identify the label/model when the equipment is available, then select the exact official driver and test. Bluetooth was part of the earlier generic offer, not confirmed on this printer. No raw ESC/POS integration, driver download or hardware success is inferred from the brand/ports.

## Before installing anything

Obtain the exact brand/model of each device and the Windows version. Confirm scanner USB HID keyboard mode, configurable Enter suffix, printer driver availability for that Windows version, and the actual printer's connection ports. ESC/POS support is useful identification information; BiziSmart's existing print path uses the Windows/browser print dialog, not a raw ESC/POS, Bluetooth or USB driver. Do not buy/download a guessed driver or promise silent printing, auto-cutting or drawer-kick support. Scale model remains unselected.

## Scanner setup without recording a sale

1. Connect the identified scanner to the cashier PC and select its documented USB HID keyboard mode. Use the manufacturer's configuration instructions for an Enter suffix.
2. Open a blank Notepad document. Scan a real packaged product and compare every digit with the printed barcode, including leading zeroes. Confirm Enter moves to the next line. Scan twice; confirm two complete separate readings. A successful Notepad test checks keyboard output only, not catalogue registration.
3. Sign in to the intended Fresh Choice tenant using the authorized operator's own PIN/authenticator. Keep credentials private. Verify the product has that real physical barcode and the correct selling unit/packaging in BiziSmart. Never use source IDs/image numbers as invented barcodes.
4. After an actual opening float/till is configured by the operator, scan on POS. Confirm the intended product or packaging, exact displayed price and quantity. Test repeated scans and an unknown barcode without committing checkout. Review/clear this uncommitted test cart before real trading; do not discard a frozen or uncertain operation.
5. Verify scanning in the intended focused field as well as POS keyboard capture. The keyboard shortcut hook intentionally ignores text-entry fields and modified keys; use the page's explicit barcode entry when focused in an input. Unknown codes must remain a visible failure rather than create a product or sale.
6. If loyalty is enabled, use a genuinely issued BiziSmart customer card to check selection. QR capability alone does not prove loyalty-token handling. Scanning selects a customer; it does not authorize points redemption or payment.

No test sale, stock balance or cash amount is created by this guide. Use guarded local test fixtures for complete checkout/refund rehearsals. Production acceptance uses genuine business operations and the actual operator's authority.

## Printer setup without creating a new sale

1. Install the exact model's official Windows driver and connect through the confirmed port. Use the printer's self-test and the Windows printer test page first. Record any missing characters, paper feed or cutting issue separately from application printing.
2. Select the driver's actual80mm thermal roll paper setting. Open a saved receipt the signed-in operator is authorized to view, choose **Print or download receipt**, then **Print receipt**. This reprints immutable saved sale values; it does not commit another sale. Do not invent a transaction to obtain a print sample.
3. In the print dialog choose the installed thermal printer and preview the roll layout. Disable browser-added headers/footers where available, check margins/scale visually, and print one copy. The existing receipt HTML is constrained to72mm content and the driver determines the usable area; actual clipping/roll length remains a hardware test.
4. Check shop name, receipt number/date, long product names, exact item/weight quantities, discounts, total, settlement and change. Compare with the saved receipt. Test English/Kiswahili text actually used by the shop. An ordinary BiziSmart printout is not a fiscal receipt or TRA approval.
5. **Download PDF** is the existing paginated document fallback. It does not configure the thermal printer or prove80mm output; select an appropriate PDF print layout separately. Cancelled print dialogs must not change sales or cash.
6. Disconnect/reconnect the printer and rehearse the operator's recovery using the same saved receipt. Do not repeat checkout because printing failed. Automatic printing, cash-drawer pulse, cutters and vendor USB/serial/Bluetooth protocols remain unverified until the selected hardware is tested.

## Acceptance record

Software-only follow-up (D107): focused scanner/private-image/receipt regression13/13 passes, including leading-zero keyboard burst capture, editable-field/locked-operation protection, saved exact receipt amounts, output escaping, PDF pagination and isolated print preview. No physical hardware was attached; this does not replace the steps above.

Record the actual models/driver versions, scanner mode/suffix and sample codes, printer connection/paper settings, Windows/browser versions, date/operator, saved receipt reference and pass/fail notes. Do not record PINs, authenticator secrets, API keys or customer details in the handoff. Current status: Windows confirmed; device models/drivers/physical acceptance pending. No hardware was installed or tested by the software-only browser suite.
