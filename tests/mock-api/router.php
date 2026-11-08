<?php
/* Copyright (C) 2026 FacturX API - GPL-3.0-or-later
 *
 * Test double of the FacturX API for the integration test and the screenshots:
 *   php -S 127.0.0.1:8765 tests/mock-api/router.php
 * It answers with the documented shapes (tests/fixtures), never with a real verdict.
 * Every request is appended to $MOCK_LOG (default /tmp/facturx-mock.log) as one JSON line.
 */

$fixtures = __DIR__.'/../fixtures/';
$log = getenv('MOCK_LOG') ?: sys_get_temp_dir().'/facturx-mock.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = function_exists('getallheaders') ? getallheaders() : array();
$key = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : (isset($headers['X-Api-Key']) ? $headers['X-Api-Key'] : '');
$upload = isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) ? (string) file_get_contents($_FILES['file']['tmp_name']) : '';

file_put_contents($log, json_encode(array(
	'path' => $path, 'query' => isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '',
	'user_agent' => isset($headers['User-Agent']) ? $headers['User-Agent'] : '',
	'client' => isset($headers['X-FacturX-Client']) ? $headers['X-FacturX-Client'] : (isset($headers['X-Facturx-Client']) ? $headers['X-Facturx-Client'] : ''),
	'idempotency' => isset($headers['Idempotency-Key']) ? $headers['Idempotency-Key'] : '',
	'file' => isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '', 'constraints' => isset($_POST['constraints']) ? $_POST['constraints'] : '',
))."\n", FILE_APPEND);

header('Content-Type: application/json');
header('X-Request-ID: 11111111-2222-4333-8444-555555555555');

function answer($code, $body)
{
	unset($body['_fixture_source'], $body['_status']);
	http_response_code($code);
	echo json_encode($body);
	exit;
}

if ($key !== 'fx_mock_key') {
	answer(401, json_decode((string) file_get_contents($fixtures.'validate-401-invalid-key.recorded.json'), true));
}
if ($path === '/api/v1/validate') {
	$name = (strpos($upload, 'MOCK-BLOCKING') !== false) ? 'validate-schematron-fr.synthetic.json' : 'validate-valid.openapi-example.json';
	$body = json_decode((string) file_get_contents($fixtures.$name), true);
	$body['filename'] = isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '';
	answer(200, $body);
}
if ($path === '/api/v1/repair') {
	$body = json_decode((string) file_get_contents($fixtures.'repair.openapi-example.json'), true);
	$body['repaired_xml'] = base64_encode(str_replace('MOCK-BLOCKING', 'MOCK-REPAIRED', $upload));
	answer(200, $body);
}
answer(404, array('detail' => array('error' => 'not_found', 'message' => 'mock: '.$path)));
