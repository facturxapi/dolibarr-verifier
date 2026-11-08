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
 * \file    facturxverify/tab.php
 * \ingroup facturxverify
 * \brief   "Vérification" tab of a customer invoice: check the electronic invoice file before it is sent.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php using the path of the script (works with a symlinked module, as the ModuleBuilder template)
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
dol_include_once('/facturxverify/class/FacturxVerifyService.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'facturxverify@facturxverify'));

$id = (int) GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$filename = GETPOST('file', 'alphanohtml');

if (!isModEnabled('facturxverify')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('facturxverify', 'read')) {
	accessforbidden();
}

$object = new Facture($db);
if ($object->fetch($id, $ref) <= 0) {
	accessforbidden('Invoice not found');
}
$result = restrictedArea($user, 'facture', $object->id, '');

$service = new FacturxVerifyService($db);
$apiLang = (strpos($langs->defaultlang, 'fr') === 0) ? 'fr' : 'en';
$sessionKey = 'facturxverify_repair_'.$object->id;
$canCheck = $user->hasRight('facturxverify', 'check');

/*
 * Actions
 */
$repair = null;
if ($action === 'check' && $canCheck) {
	if (!FacturxVerifyService::isConfigured()) {
		setEventMessages($langs->trans('FacturxVerifyNotConfigured'), null, 'errors');
	} else {
		$report = $service->check($object, $user, $filename, $apiLang);
		if ($report === null) {
			setEventMessages($langs->trans($service->error), null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}
if ($action === 'repair' && $canCheck) {
	if (!FacturxVerifyService::isConfigured()) {
		setEventMessages($langs->trans('FacturxVerifyNotConfigured'), null, 'errors');
	} else {
		$repair = $service->repair($object, $user, $filename, $apiLang);
		if ($repair === null) {
			setEventMessages($langs->trans($service->error), null, 'errors');
		} elseif ($repair['ok'] && $repair['xml'] !== '' && $repair['modified']) {
			$_SESSION[$sessionKey] = array('source' => $repair['source']['name'], 'xml' => $repair['xml'], 'at' => dol_now());
		}
	}
}
if ($action === 'saverepair' && $canCheck) {
	$pending = isset($_SESSION[$sessionKey]) ? $_SESSION[$sessionKey] : null;
	if (!is_array($pending) || (dol_now() - (int) $pending['at']) > 3600) {
		setEventMessages($langs->trans('FacturxVerifyRepairExpired'), null, 'errors');
	} else {
		$written = $service->saveRepaired($object, $pending['source'], $pending['xml']);
		unset($_SESSION[$sessionKey]);
		if ($written === '') {
			setEventMessages($langs->trans($service->error), null, 'errors');
		} else {
			setEventMessages($langs->trans('FacturxVerifyRepairSaved', $written), null, 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $object->ref.' - '.$langs->trans('FacturxVerifyTab'), '', '', 0, 0, '', '', '', 'mod-facturxverify page-tab');

$object->fetch_thirdparty();
$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'facturxverify', $langs->trans('InvoiceCustomer'), -1, 'bill');
$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">'.$object->thirdparty->getNomUrl(1, 'customer').'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0, '', '');
print dol_get_fiche_end();

print '<div class="fichecenter facturxverify">';

if (!FacturxVerifyService::isConfigured()) {
	print '<div class="warning">'.$langs->trans('FacturxVerifyNotConfigured');
	if ($user->admin) {
		print ' <a href="'.dol_buildpath('/facturxverify/admin/setup.php', 1).'">'.$langs->trans('FacturxVerifyGoToSetup').'</a>';
	}
	print '</div>';
}

// File picker + check button
$candidates = $service->candidates($object);
print load_fiche_titre($langs->trans('FacturxVerifyTitle'), '', 'fa-file-circle-check');
print '<p class="opacitymedium">'.$langs->trans('FacturxVerifyIntro').'</p>';
if (empty($candidates)) {
	print '<div class="info">'.$langs->trans('FacturxVerifyNoFile').'</div>';
} else {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" class="facturxverify-form">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="check">';
	print '<label for="facturxverify-file">'.$langs->trans('FacturxVerifyFile').'</label> ';
	print '<select id="facturxverify-file" name="file" class="minwidth300">';
	foreach ($candidates as $c) {
		print '<option value="'.dol_escape_htmltag($c['name']).'">'.dol_escape_htmltag($c['name']).' — '.$langs->trans('FacturxVerifyKind_'.$c['kind']).'</option>';
	}
	print '</select> ';
	$disabled = ($canCheck && FacturxVerifyService::isConfigured()) ? '' : ' disabled';
	print '<input type="submit" class="button button-save" id="facturxverify-check" value="'.dol_escape_htmltag($langs->trans('FacturxVerifyCheckButton')).'"'.$disabled.'>';
	print '<div class="opacitymedium small paddingtop">'.$langs->trans('FacturxVerifyQuotaNotice').'</div>';
	print '</form>';
}

// Repair preview (just requested)
if (is_array($repair) && $repair['ok']) {
	print '<div class="facturxverify-repair">';
	print load_fiche_titre($langs->trans('FacturxVerifyRepairTitle'), '', '');
	if (!$repair['modified']) {
		print '<div class="info">'.$langs->trans('FacturxVerifyRepairNothing').'</div>';
	} else {
		print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('FacturxVerifyChange').'</th><th>'.$langs->trans('FacturxVerifyBefore').'</th><th>'.$langs->trans('FacturxVerifyAfter').'</th></tr>';
		foreach ($repair['changes'] as $ch) {
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($ch['message']).'<div class="opacitymedium small">'.dol_escape_htmltag($ch['path']).'</div></td>';
			print '<td><code>'.dol_escape_htmltag($ch['before']).'</code></td><td><code>'.dol_escape_htmltag($ch['after']).'</code></td></tr>';
		}
		print '</table>';
		$after = $repair['validAfter'] === true ? $langs->trans('FacturxVerifyRepairAfterOk') : $langs->trans('FacturxVerifyRepairAfterKo', (int) $repair['errorsAfter']);
		print '<p>'.$after.'</p>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saverepair">';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('FacturxVerifySaveRepair')).'"> ';
		print '<span class="opacitymedium small">'.$langs->trans('FacturxVerifySaveRepairNotice').'</span>';
		print '</form>';
	}
	print '</div>';
} elseif (is_array($repair)) {
	print '<div class="error">'.$langs->trans('FacturxVerifyApiError').' '.dol_escape_htmltag($repair['errorCode'].' '.$repair['errorMessage']).'</div>';
}

// Last check
$history = $service->history($object->id, 10);
$last = null;
foreach ($history as $h) {
	if ($h->operation === 'validate') {
		$last = $h;
		break;
	}
}
if ($last !== null) {
	$res = new FacturxApiResult();
	$res->status = $last->verdict === 'error' ? 0 : 200;
	$decoded = json_decode((string) $last->report_json, true);
	$res->data = is_array($decoded) ? $decoded : array();
	$res->requestId = (string) $last->request_id;
	if ($last->verdict === 'error') {
		$res->transportError = 'stored_error';
	}
	$report = FacturxReport::fromValidate($res);

	print '<div class="facturxverify-result facturxverify-'.dol_escape_htmltag($last->verdict).'">';
	print load_fiche_titre($langs->trans('FacturxVerifyLastResult'), '', '');
	$badge = array('ready' => 'status4', 'not_ready' => 'status8', 'not_evaluated' => 'status1', 'error' => 'status8');
	$st = isset($badge[$last->verdict]) ? $badge[$last->verdict] : 'status0';
	print '<div class="facturxverify-verdict"><span class="badge badge-'.$st.' badge-status">'.$langs->trans('FacturxVerifyVerdict_'.$last->verdict).'</span> ';
	print '<span class="facturxverify-headline">'.$langs->trans('FacturxVerifyHeadline_'.$last->verdict, (int) $last->error_count).'</span></div>';
	print '<div class="opacitymedium small paddingtop">'.dol_escape_htmltag($last->file_name).' · '.dol_print_date($db->jdate($last->datec), 'dayhour');
	print ' · '.$langs->trans('FacturxVerifyRules_'.$last->target);
	if ($report->profile !== '') {
		print ' · '.$langs->trans('FacturxVerifyProfile').' '.dol_escape_htmltag($report->profile);
	}
	print '</div>';
	if ($last->verdict === 'not_evaluated' && $report->notRunReason !== '') {
		print '<div class="warning">'.$langs->trans('FacturxVerifyNotRunReason').' <code>'.dol_escape_htmltag($report->notRunReason).'</code></div>';
	}

	if (!empty($report->issues)) {
		print '<table class="noborder centpercent facturxverify-issues"><tr class="liste_titre"><th>'.$langs->trans('FacturxVerifyRule').'</th><th>'.$langs->trans('FacturxVerifyMessage').'</th><th>'.$langs->trans('FacturxVerifyWhere').'</th></tr>';
		foreach ($report->issues as $i) {
			$sev = $i['severity'] === 'error' ? '<span class="badge badge-status8 badge-status">'.$langs->trans('FacturxVerifyBlocking').'</span>' : '<span class="badge badge-status1 badge-status">'.$langs->trans('FacturxVerifyWarning').'</span>';
			$rule = $i['rule'];
			$ruleHtml = dol_escape_htmltag($rule);
			if (preg_match('/^BR-[A-Z0-9-]+$/', $rule)) {
				$ruleHtml = '<a href="https://facturxapi.com/errors/'.strtolower($rule).'?utm_source=dolibarr&utm_medium=module&utm_campaign=connectors-b" target="_blank" rel="noopener">'.$ruleHtml.'</a>';
			}
			print '<tr class="oddeven"><td class="nowraponall">'.$sev.' '.$ruleHtml.'</td><td>'.dol_escape_htmltag($i['message']);
			if ($i['proposed'] !== '') {
				print '<div class="small">'.$langs->trans('FacturxVerifyProposed').' <code>'.dol_escape_htmltag($i['proposed']).'</code></div>';
			}
			print '</td><td class="small opacitymedium tdoverflowmax300" title="'.dol_escape_htmltag($i['location']).'">'.dol_escape_htmltag($i['location']).'</td></tr>';
		}
		print '</table>';
	}

	// Repair offer: only for a CII XML with blocking errors
	$lastFile = $service->pick($object, (string) $last->file_name);
	if ($last->verdict === 'not_ready' && $lastFile !== null && $lastFile['kind'] === FacturxFileLocator::KIND_CII && $canCheck && !is_array($repair)) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" class="paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="repair">';
		print '<input type="hidden" name="file" value="'.dol_escape_htmltag($lastFile['name']).'">';
		print '<input type="submit" class="button" id="facturxverify-repair" value="'.dol_escape_htmltag($langs->trans('FacturxVerifyRepairButton')).'"> ';
		print '<span class="opacitymedium small">'.$langs->trans('FacturxVerifyRepairNotice').'</span>';
		print '</form>';
	}
	if ($report->legalDisclaimer !== '') {
		print '<div class="opacitymedium small paddingtop">'.dol_escape_htmltag($report->legalDisclaimer).'</div>';
	}
	if ($report->requestId !== '') {
		print '<div class="opacitymedium small">'.$langs->trans('FacturxVerifyRequestId').' <code>'.dol_escape_htmltag($report->requestId).'</code></div>';
	}
	print '</div>';
}

// History
if (count($history) > 1) {
	print load_fiche_titre($langs->trans('FacturxVerifyHistory'), '', '');
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('FacturxVerifyOperation').'</th><th>'.$langs->trans('FacturxVerifyFile').'</th><th>'.$langs->trans('FacturxVerifyResult').'</th></tr>';
	foreach ($history as $h) {
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($h->datec), 'dayhour').'</td><td>'.$langs->trans('FacturxVerifyOp_'.$h->operation).'</td>';
		print '<td>'.dol_escape_htmltag($h->file_name).'</td><td>'.$langs->trans('FacturxVerifyVerdict_'.$h->verdict).($h->error_count ? ' ('.((int) $h->error_count).')' : '').'</td></tr>';
	}
	print '</table>';
}

print '</div>';

llxFooter();
$db->close();
