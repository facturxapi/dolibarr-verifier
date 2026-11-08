# Changelog

## 0.1.0 — 2026-11-08
- First version: "Check before sending" button on the customer invoice card and "Check" tab.
- Finds the e-invoice file in the invoice documents (CII XML, Factur-X PDF, UBL XML).
- Check against the official rules through FacturX API `/api/v1/validate` (French rules or EN 16931 only).
- Form-only repair of a CII XML through `/api/v1/repair`, amounts locked, saved next to the original.
- History of checks per invoice. FR / EN.
