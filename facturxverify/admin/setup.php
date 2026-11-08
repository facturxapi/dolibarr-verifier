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
 * \file    facturxverify/admin/setup.php
 * \ingroup facturxverify
 * \brief   Setup page: free API key, rules checked, API address.
 */

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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/facturxverify/class/FacturxVerifyService.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'facturxverify@facturxverify'));
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
const FACTURXVERIFY_KEY_URL = 'https://facturxapi.com/api-key?source=dolibarr&utm_source=dolibarr&utm_medium=module&utm_campaign=connectors-b';

if ($action === 'save') {
	$error = 0;
	$key = trim(GETPOST('apikey', 'none'));
	$url = trim(GETPOST('apiurl', 'alphanohtml'));
	$target = GETPOST('target', 'aZ09');
	if ($url === '' || !FacturxApiClient::isAllowedBaseUrl($url)) {
		setEventMessages($langs->trans('FacturxVerifyErrorUrl'), null, 'errors');
		$error++;
	}
	if (!in_array($target, array('france_2026', 'en16931'), true)) {
		$target = 'france_2026';
	}
	if (!$error) {
		dolibarr_set_const($db, 'FACTURXVERIFY_API_URL', $url, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'FACTURXVERIFY_TARGET', $target, 'chaine', 0, '', $conf->entity);
		// The key field is left empty on display: an empty submit keeps the stored key.
		if ($key !== '') {
			$stored = function_exists('dolEncrypt') ? dolEncrypt($key) : $key;
			dolibarr_set_const($db, 'FACTURXVERIFY_API_KEY', $stored, 'chaine', 0, '', $conf->entity);
		}
		if (GETPOST('removekey', 'int')) {
			dolibarr_del_const($db, 'FACTURXVERIFY_API_KEY', $conf->entity);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$title = $langs->trans('FacturxVerifySetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-facturxverify page-admin');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print '<div class="info">'.$langs->trans('FacturxVerifySetupIntro').'</div>';

$hasKey = FacturxVerifyService::apiKey() !== '';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('FacturxVerifyApiKey').'</td><td>';
print '<input type="password" name="apikey" id="apikey" class="minwidth400" autocomplete="off" value="" placeholder="'.dol_escape_htmltag($hasKey ? $langs->trans('FacturxVerifyKeyStored') : 'fx_...').'"> ';
print '<a href="'.FACTURXVERIFY_KEY_URL.'" target="_blank" rel="noopener" id="facturxverify-getkey">'.$langs->trans('FacturxVerifyGetKey').'</a>';
if ($hasKey) {
	print '<br><label><input type="checkbox" name="removekey" value="1"> '.$langs->trans('FacturxVerifyRemoveKey').'</label>';
}
print '<div class="opacitymedium small">'.$langs->trans('FacturxVerifyApiKeyHelp').'</div></td></tr>';

$target = FacturxVerifyService::target();
print '<tr class="oddeven"><td>'.$langs->trans('FacturxVerifyTarget').'</td><td><select name="target" id="target">';
foreach (array('france_2026', 'en16931') as $t) {
	print '<option value="'.$t.'"'.($t === $target ? ' selected' : '').'>'.$langs->trans('FacturxVerifyRules_'.$t).'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('FacturxVerifyApiUrl').'</td><td><input type="text" name="apiurl" class="minwidth400" value="'.dol_escape_htmltag(FacturxVerifyService::baseUrl()).'">';
print '<div class="opacitymedium small">'.$langs->trans('FacturxVerifyApiUrlHelp').'</div></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br><div class="opacitymedium small">'.$langs->trans('FacturxVerifyPrivacy').'</div>';

llxFooter();
$db->close();
