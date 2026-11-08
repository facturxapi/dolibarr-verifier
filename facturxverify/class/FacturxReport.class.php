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
 * \file    facturxverify/class/FacturxReport.class.php
 * \ingroup facturxverify
 * \brief   Reads an API answer into what the invoice card shows. Decides nothing on its own:
 *          the verdict is the API's (valid + target.status), this class only reads it.
 */

/**
 * Display model of a validation report.
 */
class FacturxReport
{
	const VERDICT_READY = 'ready';
	const VERDICT_NOT_READY = 'not_ready';
	const VERDICT_NOT_EVALUATED = 'not_evaluated';
	const VERDICT_ERROR = 'error';

	/** @var string One of the VERDICT_* constants */
	public $verdict = self::VERDICT_ERROR;
	/** @var string Profile detected by the API (EN16931, BASIC, EXTENDED...) */
	public $profile = '';
	/** @var string Target requested (france_2026 / en16931) */
	public $targetRequested = '';
	/** @var string Target status served by the API (verified, failed, not_run...) */
	public $targetStatus = '';
	/** @var string Why the target was not run, as served */
	public $notRunReason = '';
	/** @var int */
	public $errorCount = 0;
	/** @var int */
	public $warningCount = 0;
	/** @var array<int,array{severity:string,rule:string,message:string,location:string,proposed:string,stage:string}> */
	public $issues = array();
	/** @var string */
	public $legalDisclaimer = '';
	/** @var string */
	public $requestId = '';
	/** @var string API error code when the call itself failed */
	public $apiErrorCode = '';
	/** @var string API error message when the call itself failed */
	public $apiErrorMessage = '';

	/**
	 * Build the model from a validate answer.
	 *
	 * @param FacturxApiResult $result API result
	 * @return FacturxReport
	 */
	public static function fromValidate($result)
	{
		$r = new self();
		$r->requestId = $result->requestId;
		if (!$result->ok()) {
			$err = $result->apiError();
			$r->verdict = self::VERDICT_ERROR;
			$r->apiErrorCode = $result->transportError !== '' ? $result->transportError : $err['code'];
			$r->apiErrorMessage = $err['message'];
			return $r;
		}
		$d = $result->data;
		$r->profile = isset($d['profile']) ? (string) $d['profile'] : '';
		$r->legalDisclaimer = isset($d['legalDisclaimer']) ? (string) $d['legalDisclaimer'] : '';
		$target = (isset($d['target']) && is_array($d['target'])) ? $d['target'] : array();
		$r->targetRequested = isset($target['requested']) ? (string) $target['requested'] : '';
		$r->targetStatus = isset($target['status']) ? (string) $target['status'] : '';
		$r->notRunReason = isset($target['not_run_reason']) ? (string) $target['not_run_reason'] : '';

		$r->issues = self::readIssues($d);
		$counted = array('error' => 0, 'warning' => 0);
		foreach ($r->issues as $i) {
			if (isset($counted[$i['severity']])) {
				$counted[$i['severity']]++;
			}
		}
		$summary = (isset($d['summary']) && is_array($d['summary'])) ? $d['summary'] : array();
		$r->errorCount = isset($summary['errorCount']) ? (int) $summary['errorCount'] : $counted['error'];
		$r->warningCount = isset($summary['warningCount']) ? (int) $summary['warningCount'] : $counted['warning'];

		$valid = array_key_exists('valid', $d) ? $d['valid'] : null;
		if ($valid === false || $r->errorCount > 0) {
			$r->verdict = self::VERDICT_NOT_READY;
		} elseif ($valid === true && ($r->targetStatus === '' || $r->targetStatus === 'verified')) {
			$r->verdict = self::VERDICT_READY;
		} else {
			// valid=true but the requested rules were not all evaluated: never show it as ready.
			$r->verdict = self::VERDICT_NOT_EVALUATED;
		}
		return $r;
	}

	/**
	 * Issues in the unified format (issues[]) or the legacy one (errors[] + warnings[]),
	 * errors first, without duplicates.
	 *
	 * @param array<string,mixed> $d Decoded body
	 * @return array<int,array{severity:string,rule:string,message:string,location:string,proposed:string,stage:string}>
	 */
	public static function readIssues($d)
	{
		$raw = array();
		if (!empty($d['issues']) && is_array($d['issues'])) {
			$raw = $d['issues'];
		} else {
			foreach (array('errors' => 'error', 'warnings' => 'warning') as $key => $sev) {
				if (!empty($d[$key]) && is_array($d[$key])) {
					foreach ($d[$key] as $item) {
						if (is_array($item) && empty($item['severity'])) {
							$item['severity'] = $sev;
						}
						$raw[] = $item;
					}
				}
			}
		}
		$out = array();
		$seen = array();
		foreach ($raw as $item) {
			if (!is_array($item)) {
				continue;
			}
			$severity = strtolower(isset($item['severity']) ? (string) $item['severity'] : 'error');
			if ($severity === 'fatal') {
				$severity = 'error';
			}
			$rule = '';
			foreach (array('ruleId', 'rule', 'code') as $k) {
				if (!empty($item[$k])) {
					$rule = (string) $item[$k];
					break;
				}
			}
			$location = !empty($item['location']) ? (string) $item['location'] : (!empty($item['xpath']) ? (string) $item['xpath'] : '');
			$proposed = '';
			if (!empty($item['correction']) && is_array($item['correction']) && isset($item['correction']['proposed']) && is_scalar($item['correction']['proposed'])) {
				$proposed = (string) $item['correction']['proposed'];
			}
			$entry = array(
				'severity' => $severity,
				'rule' => $rule,
				'message' => isset($item['message']) ? (string) $item['message'] : '',
				'location' => $location,
				'proposed' => $proposed,
				'stage' => isset($item['stage']) ? (string) $item['stage'] : '',
			);
			$key = $entry['severity'].'|'.$entry['rule'].'|'.$entry['message'].'|'.$entry['location'];
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $entry;
		}
		usort($out, function ($a, $b) {
			$rank = array('error' => 0, 'warning' => 1);
			$ra = isset($rank[$a['severity']]) ? $rank[$a['severity']] : 2;
			$rb = isset($rank[$b['severity']]) ? $rank[$b['severity']] : 2;
			return $ra - $rb;
		});
		return $out;
	}

	/**
	 * Read a repair answer.
	 *
	 * @param FacturxApiResult $result API result
	 * @return array{ok:bool,modified:bool,successful:bool,xml:string,changes:array<int,array{message:string,path:string,before:string,after:string,rule:string}>,errorsBefore:int,errorsAfter:int,validAfter:?bool,errorCode:string,errorMessage:string}
	 */
	public static function fromRepair($result)
	{
		$out = array('ok' => false, 'modified' => false, 'successful' => false, 'xml' => '', 'changes' => array(),
			'errorsBefore' => 0, 'errorsAfter' => 0, 'validAfter' => null, 'errorCode' => '', 'errorMessage' => '');
		if (!$result->ok()) {
			$err = $result->apiError();
			$out['errorCode'] = $result->transportError !== '' ? $result->transportError : $err['code'];
			$out['errorMessage'] = $err['message'];
			return $out;
		}
		$d = $result->data;
		$out['ok'] = true;
		$out['modified'] = !empty($d['wasModified']) || !empty($d['repairApplied']);
		$out['successful'] = !empty($d['repairSuccessful']);
		if (!empty($d['repaired_xml']) && is_string($d['repaired_xml'])) {
			$xml = base64_decode($d['repaired_xml'], true);
			$out['xml'] = $xml === false ? '' : $xml;
		}
		if (!empty($d['diff_summary']) && is_array($d['diff_summary'])) {
			foreach ($d['diff_summary'] as $c) {
				if (!is_array($c)) {
					continue;
				}
				$out['changes'][] = array(
					'message' => isset($c['message']) ? (string) $c['message'] : '',
					'path' => isset($c['path']) ? (string) $c['path'] : '',
					'before' => isset($c['beforeValue']) && is_scalar($c['beforeValue']) ? (string) $c['beforeValue'] : '',
					'after' => isset($c['afterValue']) && is_scalar($c['afterValue']) ? (string) $c['afterValue'] : '',
					'rule' => isset($c['ruleReference']) ? (string) $c['ruleReference'] : '',
				);
			}
		}
		foreach (array('validation_before' => 'errorsBefore', 'validation_after' => 'errorsAfter') as $k => $dest) {
			if (!empty($d[$k]['summary']['errorCount'])) {
				$out[$dest] = (int) $d[$k]['summary']['errorCount'];
			}
		}
		if (isset($d['validation_after']['valid'])) {
			$out['validAfter'] = (bool) $d['validation_after']['valid'];
		}
		return $out;
	}
}
