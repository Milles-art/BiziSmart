# English and Kiswahili interface

The approved operations batch supports English and Kiswahili without changing the supplied layout. `frontend/src/i18n` contains explicit English message keys, reviewed Kiswahili messages, a React external store and stable API error-code mappings. Unknown contextual messages fall back to their original English text. Product, shop, staff, supplier and customer names, barcodes, public IDs, units and API enums remain unchanged.

The sign-in page and workspace toolbar let every user switch language. Settings also exposes explicit English/Kiswahili buttons. Switching updates existing components without remounting the cart or form. Only `bizismart-language` with `en` or `sw` is saved in localStorage; no cart, financial data, identity, permissions, PIN, challenge or secret is saved by localisation. Invalid values and unavailable storage fall back safely; a valid preference change from another tab updates the interface.

Saved receipt preview, print and PDF use translated labels and the original immutable sale quantities, money, VAT and payment snapshots. The PDF captures the selected language before its asynchronous dependency load. Receipt HTML uses the selected document language, escapes shop/product names and keeps the existing isolated printable frame. Tanzania event times remain authoritative.

API error codes are stable machine identifiers. Known codes receive Kiswahili guidance; validation detail and unknown contextual messages remain available with English fallback. Translation never changes request payloads, approval fingerprints, pricing, stock, payment state or authorisation.

Focused checks cover live switching without cart/uncertain-operation mutation, field preservation, preference-only persistence, unavailable storage, invalid/cross-tab preferences, receipt snapshot preservation/escaping and known/unmapped error handling. Final browser acceptance and test counts are recorded in the main batch evidence.
