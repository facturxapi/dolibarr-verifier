<?php
/* Copyright (C) 2026 FacturX API - GPL-3.0-or-later
 *
 * Real calls to the STAGING API with the module's own client, to record the answers the
 * tests use. The key is read from a file and never printed.
 *   php dev/staging-smoke.php <key_file> <valid_cii.xml> [<factur-x.pdf>]
 * Writes tests/fixtures/*.staging.json (request ids kept, nothing secret in them).
 */

require __DIR__.'/../facturxverify/class/FacturxApiClient.class.php';
require __DIR__.'/../facturxverify/class/FacturxReport.class.php';

$key = trim((string) file_get_contents($argv[1]));
$validXml = (string) file_get_contents($argv[2]);
$pdf = isset($argv[3]) ? (string) file_get_contents($argv[3]) : '';
$base = getenv('FACTURX_BASE_URL') ?: 'https://api-staging.facturxapi.com';
if (strpos($base, 'api-staging.') === false) {
	fwrite(STDERR, "refusing: staging only\n");
	exit(2);
}
$client = new FacturxApiClient($base, $key, '0.1.0', 'staging-smoke');
$out = __DIR__.'/../tests/fixtures/';
$stamp = gmdate('Y-m-d\TH:i:s\Z');

/**
 * @param string           $name   Fixture name
 * @param FacturxApiResult $r      Result
 * @param string           $what   Description
 * @return void
 */
function save($name, $r, $what)
{
	global $out, $stamp, $base;
	$body = $r->data;
	if (isset($body['repaired_xml'])) {
		$body['repaired_xml_sha256'] = hash('sha256', (string) base64_decode($body['repaired_xml']));
		$body['repaired_xml'] = base64_encode('<!-- omitted, see repaired_xml_sha256 -->');
	}
	$body = array('_fixture_source' => "Real answer of $base, $what, recorded $stamp by dev/staging-smoke.php (HTTP {$r->status}).", '_status' => $r->status) + $body;
	file_put_contents($out.$name, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
	$rep = FacturxReport::fromValidate($r);
	echo str_pad($name, 44)." HTTP {$r->status} verdict={$rep->verdict} errors={$rep->errorCount} warnings={$rep->warningCount} profile={$rep->profile} target={$rep->targetStatus}\n";
}

// 1. A valid public FNFE example (CII, EN 16931 profile)
save('validate-fnfe-valid.staging.json', $client->validate('fnfe-uc2.xml', $validXml, 'france_2026', 'fr'), 'validate france_2026 of FNFE France_RFE UC2 CII EN16931');

// 2. The same file with a malformed issue date (ISO instead of format 102): blocking, repairable
$broken = preg_replace('#(<udt:DateTimeString format="102">)(\d{4})(\d{2})(\d{2})(</udt:DateTimeString>)#', '${1}$2-$3-$4$5', $validXml, 1);
save('validate-fnfe-bad-date.staging.json', $client->validate('fnfe-uc2-bad-date.xml', $broken, 'france_2026', 'fr'), 'validate of the same file with an ISO issue date');
$rep = $client->repair('fnfe-uc2-bad-date.xml', $broken, 'fr');
$parsed = FacturxReport::fromRepair($rep);
save('repair-fnfe-bad-date.staging.json', $rep, 'repair of the ISO-date file');
echo '  repair: modified='.var_export($parsed['modified'], true).' successful='.var_export($parsed['successful'], true).' changes='.count($parsed['changes']).' validAfter='.var_export($parsed['validAfter'], true)."\n";

// 3. A Factur-X PDF
if ($pdf !== '') {
	save('validate-fnfe-pdf.staging.json', $client->validate('fnfe.pdf', $pdf, 'france_2026', 'fr'), 'validate france_2026 of an FNFE Factur-X PDF');
}
