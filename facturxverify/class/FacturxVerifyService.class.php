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
 * \file    facturxverify/class/FacturxVerifyService.class.php
 * \ingroup facturxverify
 * \brief   Runs a check or a repair for one invoice and records it.
 */

require_once __DIR__.'/FacturxApiClient.class.php';
require_once __DIR__.'/FacturxReport.class.php';
require_once __DIR__.'/FacturxFileLocator.class.php';

/**
 * Service used by the invoice tab and the action hook.
 */
class FacturxVerifyService
{
	const MODULE_VERSION = '0.1.0';

	/** @var DoliDB */
	private $db;
	/** @var callable|null Transport injected by tests */
	public $transport = null;
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return string API key, decrypted when it was stored encrypted
	 */
	public static function apiKey()
	{
		$stored = getDolGlobalString('FACTURXVERIFY_API_KEY');
		if ($stored !== '' && function_exists('dolDecrypt')) {
			$plain = dolDecrypt($stored);
			if ($plain !== '') {
				return $plain;
			}
		}
		return $stored;
	}

	/**
	 * @return bool Module configured (key present, base URL allowed)
	 */
	public static function isConfigured()
	{
		return self::apiKey() !== '' && FacturxApiClient::isAllowedBaseUrl(self::baseUrl());
	}

	/**
	 * @return string
	 */
	public static function baseUrl()
	{
		return getDolGlobalString('FACTURXVERIFY_API_URL', 'https://api.facturxapi.com');
	}

	/**
	 * @return string france_2026 or en16931
	 */
	public static function target()
	{
		$t = getDolGlobalString('FACTURXVERIFY_TARGET', 'france_2026');
		return in_array($t, array('france_2026', 'en16931'), true) ? $t : 'france_2026';
	}

	/**
	 * @return FacturxApiClient
	 */
	public function client()
	{
		return new FacturxApiClient(self::baseUrl(), self::apiKey(), self::MODULE_VERSION, defined('DOL_VERSION') ? DOL_VERSION : '', 90, $this->transport);
	}

	/**
	 * Documents directory of an invoice.
	 *
	 * @param Facture $invoice Invoice
	 * @return string
	 */
	public static function invoiceDir($invoice)
	{
		global $conf;
		$base = '';
		if (function_exists('getMultidirOutput')) {
			$base = (string) getMultidirOutput($invoice, 'facture');
		}
		if ($base === '' && !empty($conf->facture->multidir_output[$invoice->entity])) {
			$base = $conf->facture->multidir_output[$invoice->entity];
		}
		if ($base === '' && !empty($conf->facture->dir_output)) {
			$base = $conf->facture->dir_output;
		}
		return rtrim($base, '/').'/'.dol_sanitizeFileName($invoice->ref);
	}

	/**
	 * @param Facture $invoice Invoice
	 * @return array<int,array{path:string,name:string,kind:string,size:int,mtime:int}>
	 */
	public function candidates($invoice)
	{
		return FacturxFileLocator::candidates(self::invoiceDir($invoice), dol_sanitizeFileName($invoice->ref));
	}

	/**
	 * Resolve a file name chosen in the UI to a candidate of this invoice (never a free path).
	 *
	 * @param Facture $invoice Invoice
	 * @param string  $name    File name, '' for the best candidate
	 * @return array{path:string,name:string,kind:string,size:int,mtime:int}|null
	 */
	public function pick($invoice, $name = '')
	{
		$list = $this->candidates($invoice);
		if ($name === '') {
			return $list ? $list[0] : null;
		}
		foreach ($list as $c) {
			if ($c['name'] === $name) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Check a file of the invoice and record the result.
	 *
	 * @param Facture $invoice Invoice
	 * @param User    $user    User
	 * @param string  $name    File name ('' = best candidate)
	 * @param string  $lang    fr or en
	 * @return FacturxReport|null Null when no file is available (see $this->error)
	 */
	public function check($invoice, $user, $name = '', $lang = 'fr')
	{
		$file = $this->pick($invoice, $name);
		if ($file === null) {
			$this->error = 'FacturxVerifyNoFile';
			return null;
		}
		$content = (string) file_get_contents($file['path']);
		$result = $this->client()->validate($file['name'], $content, self::target(), $lang);
		$report = FacturxReport::fromValidate($result);
		$this->record($invoice, $user, 'validate', $report->verdict, $report->errorCount, $report->warningCount,
			$report->profile, self::target(), $file['name'], hash('sha256', $content), $report->requestId, $result->data);
		return $report;
	}

	/**
	 * Ask the API for a repaired version of a CII XML of the invoice. Nothing is written yet.
	 *
	 * @param Facture $invoice Invoice
	 * @param User    $user    User
	 * @param string  $name    File name (must be a CII XML of this invoice)
	 * @param string  $lang    fr or en
	 * @return array<string,mixed>|null
	 */
	public function repair($invoice, $user, $name, $lang = 'fr')
	{
		$file = $this->pick($invoice, $name);
		if ($file === null || $file['kind'] !== FacturxFileLocator::KIND_CII) {
			$this->error = 'FacturxVerifyRepairOnlyCii';
			return null;
		}
		$content = (string) file_get_contents($file['path']);
		$result = $this->client()->repair($file['name'], $content, $lang);
		$repair = FacturxReport::fromRepair($result);
		$verdict = $repair['ok'] ? ($repair['successful'] ? 'repaired' : ($repair['modified'] ? 'partially_repaired' : 'unchanged')) : 'error';
		$stored = $result->data;
		unset($stored['repaired_xml']);
		$this->record($invoice, $user, 'repair', $verdict, $repair['errorsAfter'], 0, '', self::target(), $file['name'],
			hash('sha256', $content), $result->requestId, $stored);
		$repair['source'] = $file;
		return $repair;
	}

	/**
	 * Save a repaired XML next to the original, without overwriting it.
	 *
	 * @param Facture $invoice Invoice
	 * @param string  $source  Original file name
	 * @param string  $xml     Repaired XML
	 * @return string Name of the written file, '' on error
	 */
	public function saveRepaired($invoice, $source, $xml)
	{
		if ($xml === '' || FacturxFileLocator::xmlKind(substr($xml, 0, 4096)) !== FacturxFileLocator::KIND_CII) {
			$this->error = 'FacturxVerifyRepairInvalid';
			return '';
		}
		$dir = self::invoiceDir($invoice);
		$base = preg_replace('/\.xml$/i', '', basename($source));
		$name = $base.'_repare.xml';
		$i = 2;
		while (file_exists($dir.'/'.$name)) {
			$name = $base.'_repare_'.$i.'.xml';
			$i++;
		}
		if (file_put_contents($dir.'/'.$name, $xml) === false) {
			$this->error = 'FacturxVerifyWriteFailed';
			return '';
		}
		if (function_exists('dolChmod')) {
			dolChmod($dir.'/'.$name);
		}
		return $name;
	}

	/**
	 * @param int $invoiceId Invoice id
	 * @param int $limit     Rows
	 * @return array<int,object>
	 */
	public function history($invoiceId, $limit = 10)
	{
		global $conf;
		$rows = array();
		$sql = 'SELECT rowid, operation, verdict, error_count, warning_count, profile, target, file_name, request_id, report_json, datec';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facturxverify_check';
		$sql .= ' WHERE fk_facture = '.((int) $invoiceId).' AND entity = '.((int) $conf->entity);
		$sql .= ' ORDER BY rowid DESC';
		$sql .= $this->db->plimit((int) $limit);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = $obj;
			}
		}
		return $rows;
	}

	/**
	 * @param Facture             $invoice  Invoice
	 * @param User                $user     User
	 * @param string              $op       validate|repair
	 * @param string              $verdict  Verdict
	 * @param int                 $errors   Errors
	 * @param int                 $warnings Warnings
	 * @param string              $profile  Profile
	 * @param string              $target   Target
	 * @param string              $fileName File name
	 * @param string              $sha      SHA-256 of the file sent
	 * @param string              $reqId    Request id
	 * @param array<string,mixed> $data     API body
	 * @return int
	 */
	private function record($invoice, $user, $op, $verdict, $errors, $warnings, $profile, $target, $fileName, $sha, $reqId, $data)
	{
		global $conf;
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false || strlen($json) > 4000000) {
			$json = '{}';
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'facturxverify_check';
		$sql .= ' (entity, fk_facture, operation, verdict, error_count, warning_count, profile, target, file_name, file_sha256, request_id, report_json, datec, fk_user_creat)';
		$sql .= ' VALUES ('.((int) $conf->entity).', '.((int) $invoice->id).", '".$this->db->escape($op)."', '".$this->db->escape($verdict)."', ";
		$sql .= ((int) $errors).', '.((int) $warnings).", '".$this->db->escape(substr($profile, 0, 32))."', '".$this->db->escape($target)."', ";
		$sql .= "'".$this->db->escape(substr($fileName, 0, 255))."', '".$this->db->escape($sha)."', '".$this->db->escape(substr($reqId, 0, 64))."', ";
		$sql .= "'".$this->db->escape($json)."', '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
		return $this->db->query($sql) ? 1 : -1;
	}
}
