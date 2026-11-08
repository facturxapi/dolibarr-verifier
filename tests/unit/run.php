<?php
/* Copyright (C) 2026 FacturX API - GPL-3.0-or-later
 *
 * Unit tests without Dolibarr and without network: php tests/unit/run.php
 * Exit code 0 when every assertion holds.
 */

require __DIR__.'/../../facturxverify/class/FacturxApiClient.class.php';
require __DIR__.'/../../facturxverify/class/FacturxReport.class.php';
require __DIR__.'/../../facturxverify/class/FacturxFileLocator.class.php';

$failures = 0;
$count = 0;
function check($cond, $label)
{
	global $failures, $count;
	$count++;
	if ($cond) {
		echo "ok   - $label\n";
	} else {
		$failures++;
		echo "FAIL - $label\n";
	}
}
function fixture($name)
{
	return json_decode((string) file_get_contents(__DIR__.'/../fixtures/'.$name), true);
}
/** Transport double: records the request, answers with ($status, $body). */
function transport($status, $body, &$captured)
{
	return function ($method, $url, $headers, $payload) use ($status, $body, &$captured) {
		$captured = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $payload);
		return array($status, array('x-request-id' => 'hdr-req'), json_encode($body));
	};
}

// ---------------------------------------------------------------- client
$cap = null;
$c = new FacturxApiClient('https://api.example.test/', 'fx_test_key', '0.1.0', '22.0.5', 10, transport(200, fixture('validate-valid.openapi-example.json'), $cap));
$r = $c->validate('FA1_cii.xml', '<x/>', 'france_2026', 'fr');
check($r->ok(), 'validate 200 is ok');
check($cap['method'] === 'POST' && $cap['url'] === 'https://api.example.test/api/v1/validate?validation_target=france_2026&lang=fr', 'validate path and query');
$h = implode("\n", $cap['headers']);
check(strpos($h, 'X-API-Key: fx_test_key') !== false, 'sends X-API-Key');
check(strpos($h, 'User-Agent: facturx-dolibarr/0.1.0 (Dolibarr 22.0.5)') !== false, 'sends attribution User-Agent');
check(strpos($h, 'X-FacturX-Client: dolibarr/0.1.0') !== false, 'sends X-FacturX-Client');
check((bool) preg_match('/Idempotency-Key: [0-9a-f-]{36}/', $h), 'sends one Idempotency-Key');
check(strpos($cap['body'], 'name="file"; filename="FA1_cii.xml"') !== false && strpos($cap['body'], 'Content-Type: application/xml') !== false, 'multipart file part, xml mime');
check($r->requestId === 'hdr-req', 'request id falls back to X-Request-ID header');

$c2 = new FacturxApiClient('https://api.example.test', 'k', '0.1.0', '', 10, transport(200, array(), $cap));
$c2->repair('a.xml', '<x/>', 'en');
check($cap['url'] === 'https://api.example.test/api/v1/repair?lang=en', 'repair path');
check(strpos($cap['body'], 'name="constraints"') !== false && strpos($cap['body'], '"do_not_modify_amounts":true') !== false, 'repair never lets amounts change');

$pdf = FacturxApiClient::multipart('b', array(), 'f.pdf', "%PDF-1.7\n");
check(strpos($pdf, 'Content-Type: application/pdf') !== false, 'pdf mime detected');
check(strpos(FacturxApiClient::multipart('b', array(), "a\"b\r\n.xml", '<x/>'), 'filename="a_b__.xml"') !== false, 'filename sanitized in multipart header');

$plain = new FacturxApiClient('http://api.example.test', 'k', '0.1.0');
$r = $plain->validate('a.xml', '<x/>');
check($r->transportError === 'base_url_not_https', 'refuses plain http outside loopback');
check(FacturxApiClient::isAllowedBaseUrl('http://127.0.0.1:8765'), 'loopback http allowed for the test double');
check(!FacturxApiClient::isAllowedBaseUrl('ftp://x'), 'other schemes refused');
$nokey = new FacturxApiClient('https://api.example.test', '', '0.1.0');
check($nokey->validate('a.xml', '<x/>')->transportError === 'missing_api_key', 'no call without key');
check((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', FacturxApiClient::newUuid()), 'uuid v4');

// ---------------------------------------------------------------- report: validate
$res = new FacturxApiResult();
$res->status = 200;
$res->data = fixture('validate-valid.openapi-example.json');
$rep = FacturxReport::fromValidate($res);
check($rep->verdict === FacturxReport::VERDICT_READY && $rep->errorCount === 0 && $rep->profile === 'EN16931', 'valid example → ready');

$res->data = fixture('validate-invalid_xsd.openapi-example.json');
$rep = FacturxReport::fromValidate($res);
check($rep->verdict === FacturxReport::VERDICT_NOT_READY && $rep->errorCount === 1, 'xsd example → not ready, 1 error');
check(count($rep->issues) === 1 && $rep->issues[0]['rule'] === 'XSD_VALIDATION_ERROR' && $rep->issues[0]['location'] === 'line 42', 'issues read once (issues[] preferred over errors[])');

$res->data = fixture('validate-schematron-fr.synthetic.json');
$rep = FacturxReport::fromValidate($res);
check($rep->verdict === FacturxReport::VERDICT_NOT_READY && $rep->errorCount === 2 && $rep->warningCount === 1, 'schematron → not ready 2/1');
check($rep->issues[0]['severity'] === 'error' && $rep->issues[2]['severity'] === 'warning', 'errors listed before warnings, fatal read as error');
check($rep->issues[0]['location'] !== '' && $rep->issues[1]['proposed'] === 'FR...', 'xpath fallback and proposed correction read');

$res->data = fixture('validate-target-not-run.synthetic.json');
$rep = FacturxReport::fromValidate($res);
check($rep->verdict === FacturxReport::VERDICT_NOT_EVALUATED && $rep->notRunReason === 'profile_below_target', 'valid=true with target not_run is NEVER ready');

$res->data = array('valid' => true, 'errors' => array(), 'warnings' => array(array('message' => 'w')), 'profile' => 'EN16931', 'message' => 'Validation completed');
$rep = FacturxReport::fromValidate($res);
check($rep->verdict === FacturxReport::VERDICT_READY && $rep->warningCount === 1 && $rep->issues[0]['severity'] === 'warning', 'legacy shape read');

$err = new FacturxApiResult();
$fx = fixture('validate-401-invalid-key.recorded.json');
$err->status = 401;
$err->data = $fx;
$rep = FacturxReport::fromValidate($err);
check($rep->verdict === FacturxReport::VERDICT_ERROR && $rep->apiErrorCode === 'invalid_api_key', 'recorded staging 401 → error invalid_api_key');
$t = new FacturxApiResult();
$t->transportError = 'transport: timeout';
check(FacturxReport::fromValidate($t)->apiErrorCode === 'transport: timeout', 'transport error surfaced');

// ---------------------------------------------------------------- report: repair
$rr = new FacturxApiResult();
$rr->status = 200;
$rr->data = fixture('repair.openapi-example.json');
$rr->data['repaired_xml'] = base64_encode('<rsm:CrossIndustryInvoice xmlns:rsm="x"></rsm:CrossIndustryInvoice>');
$rp = FacturxReport::fromRepair($rr);
check($rp['ok'] && $rp['modified'] && $rp['successful'] && $rp['validAfter'] === true, 'repair example read');
check(count($rp['changes']) === 1 && $rp['changes'][0]['before'] === '2024-01-15' && $rp['changes'][0]['after'] === '20240115', 'repair diff read');
check($rp['errorsBefore'] === 2 && $rp['errorsAfter'] === 0 && strpos($rp['xml'], 'CrossIndustryInvoice') !== false, 'repair counts and xml decoded');

// ---------------------------------------------------------------- locator
$dir = __DIR__.'/../fixtures/files';
$list = FacturxFileLocator::candidates($dir, 'FA2611-0001');
$names = array_map(function ($c) {
	return $c['name'].':'.$c['kind'];
}, $list);
check($names === array('FA2611-0001_cii.xml:cii', 'FA2611-0001.pdf:facturx_pdf', 'ubl-invoice.xml:ubl', 'plain.pdf:pdf'), 'candidates ordered CII > Factur-X PDF > UBL > PDF, non-invoices ignored ('.implode(',', $names).')');
check(FacturxFileLocator::candidates($dir.'/missing', 'X') === array(), 'missing dir → no candidate');
check(FacturxFileLocator::xmlKind('<?xml version="1.0"?><CrossIndustryInvoice xmlns="urn:un:unece">') === 'cii', 'unprefixed CII detected');

echo "\n$count assertions, $failures failure(s)\n";
exit($failures ? 1 : 0);
