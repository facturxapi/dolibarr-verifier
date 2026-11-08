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
 * \file    facturxverify/class/actions_facturxverify.class.php
 * \ingroup facturxverify
 * \brief   Adds the "Vérifier avant transmission" button on the customer invoice card.
 */

/**
 * Hook class.
 */
class ActionsFacturxVerify
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array<string,mixed> */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Button on the card of a validated customer invoice.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Invoice
	 * @param string              $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0 = continue with standard buttons
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		$contexts = explode(':', (string) $parameters['context']);
		if (!in_array('invoicecard', $contexts) || empty($object->id) || $object->element !== 'facture') {
			return 0;
		}
		if (!$user->hasRight('facturxverify', 'check') || (int) $object->status < 1) {
			return 0;
		}
		$langs->load('facturxverify@facturxverify');
		$url = dol_buildpath('/facturxverify/tab.php', 1).'?id='.((int) $object->id).'&action=check&token='.newToken();
		print dolGetButtonAction($langs->trans('FacturxVerifyCheckButtonHelp'), $langs->trans('FacturxVerifyCheckButton'), 'default', $url, 'facturxverify-card-check', 1);
		return 0;
	}
}
