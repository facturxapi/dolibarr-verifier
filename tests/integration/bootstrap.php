<?php
/* Copyright (C) 2026 FacturX API - GPL-3.0-or-later
 *
 * Prepares a Dolibarr instance for the integration test (CLI):
 *   php tests/integration/bootstrap.php /path/to/htdocs <api_url> <api_key>
 * Enables the module, stores the key, creates a customer and a validated invoice,
 * and drops a CII file in the invoice documents. Prints "INVOICE_ID=<id> REF=<ref> DIR=<dir>".
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
$htdocs = rtrim($argv[1], '/');
$apiUrl = $argv[2];
$apiKey = $argv[3];

define('NOCSRFCHECK', 1);
define('NOLOGIN', 1);
$_SERVER['SCRIPT_NAME'] = 'bootstrap.php';
chdir($htdocs);
require $htdocs.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

global $db, $conf, $user, $langs, $mysoc;
$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();
if (empty($langs)) {
	$langs = new Translate('', $conf);
}
$langs->setDefaultLang('fr_FR');

// Company: French seller (needed by invoice validation)
dolibarr_set_const($db, 'MAIN_INFO_SOCIETE_NOM', 'Atelier Démo Dolibarr', 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'MAIN_INFO_SOCIETE_COUNTRY', '1:FR:France', 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'MAIN_INFO_SIREN', '345678901', 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'MAIN_INFO_TVAINTRA', 'FR15345678901', 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'MAIN_LANG_DEFAULT', 'fr_FR', 'chaine', 0, '', $conf->entity);

foreach (array('modSociete', 'modFacture', 'modFacturxVerify') as $mod) {
	$res = activateModule($mod);
	if (!empty($res['errors'])) {
		fwrite(STDERR, "activateModule $mod: ".implode(', ', $res['errors'])."\n");
		exit(2);
	}
}
$conf->setValues($db);
dol_include_once('/facturxverify/class/FacturxVerifyService.class.php');
dolibarr_set_const($db, 'FACTURXVERIFY_API_URL', $apiUrl, 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'FACTURXVERIFY_API_KEY', function_exists('dolEncrypt') ? dolEncrypt($apiKey) : $apiKey, 'chaine', 0, '', $conf->entity);

// Rights of the module for admin (admin has all rights, but load them explicitly)
$user->addrights(52891001);
$user->addrights(52891002);

$soc = new Societe($db);
$soc->name = 'Client Démo SARL';
$soc->client = 1;
$soc->country_id = 1;
$soc->idprof1 = '552100554';
$soc->code_client = 'auto';
$socid = $soc->create($user);
if ($socid <= 0) {
	fwrite(STDERR, 'thirdparty: '.$soc->error."\n");
	exit(3);
}

$inv = new Facture($db);
$inv->socid = $socid;
$inv->type = Facture::TYPE_STANDARD;
$inv->date = dol_now();
$inv->cond_reglement_id = 1;
$inv->mode_reglement_id = 2;
$id = $inv->create($user);
if ($id <= 0) {
	fwrite(STDERR, 'invoice: '.$inv->error."\n");
	exit(4);
}
$inv->addline('Prestation de démonstration', 100, 1, 20);
if ($inv->validate($user) <= 0) {
	fwrite(STDERR, 'validate: '.$inv->error."\n");
	exit(5);
}
$inv->fetch($id);

$dir = FacturxVerifyService::invoiceDir($inv);
dol_mkdir($dir);
$ref = dol_sanitizeFileName($inv->ref);
$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
	.'<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100">'
	.'<!-- MOCK-BLOCKING --><rsm:ExchangedDocument><ram:ID>'.$ref.'</ram:ID></rsm:ExchangedDocument></rsm:CrossIndustryInvoice>'."\n";
file_put_contents($dir.'/'.$ref.'_cii.xml', $xml);
echo 'INVOICE_ID='.$id.' REF='.$ref.' DIR='.$dir."\n";
