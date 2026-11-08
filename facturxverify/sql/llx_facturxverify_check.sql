-- Copyright (C) 2026 FacturX API <contact@facturxapi.com>
-- GPL-3.0-or-later. One row per check or repair run from an invoice card.

CREATE TABLE llx_facturxverify_check (
  rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity integer DEFAULT 1 NOT NULL,
  fk_facture integer NOT NULL,
  operation varchar(16) DEFAULT 'validate' NOT NULL,
  verdict varchar(32) NOT NULL,
  error_count integer DEFAULT 0 NOT NULL,
  warning_count integer DEFAULT 0 NOT NULL,
  profile varchar(32),
  target varchar(32),
  file_name varchar(255),
  file_sha256 varchar(64),
  request_id varchar(64),
  report_json mediumtext,
  datec datetime NOT NULL,
  fk_user_creat integer
) ENGINE=innodb;
