-- Copyright (C) 2026 FacturX API <contact@facturxapi.com>
-- GPL-3.0-or-later.
ALTER TABLE llx_facturxverify_check ADD INDEX idx_facturxverify_check_fk_facture (fk_facture);
ALTER TABLE llx_facturxverify_check ADD INDEX idx_facturxverify_check_entity (entity);
