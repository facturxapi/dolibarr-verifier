<?php
/* Copyright (C) 2026 FacturX API <contact@facturxapi.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \defgroup facturxverify Module FacturxVerify
 * \file     facturxverify/core/modules/modFacturxVerify.class.php
 * \ingroup  facturxverify
 * \brief    Descriptor of the module "Vérification facture électronique (FacturX API)".
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor.
 */
class modFacturxVerify extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;
		$this->db = $db;

		// Id to be reserved on https://wiki.dolibarr.org/index.php/List_of_modules_id before DoliStore publication.
		$this->numero = 528910;
		$this->rights_class = 'facturxverify';
		$this->family = 'financial';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'FacturxVerifyDescription';
		$this->descriptionlong = 'FacturxVerifyDescriptionLong';
		$this->editor_name = 'FacturX API';
		$this->editor_url = 'https://facturxapi.com';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-file-circle-check_fas_#2c7a7b';

		$this->module_parts = array(
			'hooks' => array('data' => array('invoicecard'), 'entity' => '0'),
		);
		$this->dirs = array();
		$this->config_page_url = array('setup.php@facturxverify');
		$this->hidden = false;
		$this->depends = array('modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('facturxverify@facturxverify');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(18, 0);

		$this->const = array(
			0 => array('FACTURXVERIFY_API_URL', 'chaine', 'https://api.facturxapi.com', 'FacturX API base URL', 0, 'current', 1),
			1 => array('FACTURXVERIFY_TARGET', 'chaine', 'france_2026', 'Rules checked: france_2026 (EN 16931 + French rules) or en16931', 0, 'current', 1),
		);

		$this->tabs = array(
			array('data' => 'invoice:+facturxverify:FacturxVerifyTab:facturxverify@facturxverify:$user->hasRight("facturxverify", "read"):/facturxverify/tab.php?id=__ID__'),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Read electronic invoice checks';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Run a check or a repair (uses the FacturX API quota)';
		$this->rights[$r][4] = 'check';
		$this->rights[$r][5] = '';

		$this->menu = array();
	}

	/**
	 * Called when the module is enabled: creates the table and the constants.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/facturxverify/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);
		return $this->_init(array(), $options);
	}

	/**
	 * Called when the module is disabled. Data and table are kept.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
