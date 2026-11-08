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
 * \file    facturxverify/class/FacturxFileLocator.class.php
 * \ingroup facturxverify
 * \brief   Finds, in the documents directory of an invoice, the electronic invoice file(s) produced
 *          by whatever module generates them (official e-invoicing module, CAP-REL, Akyras, Lemon...).
 *          Reads files only; never writes. No Dolibarr dependency.
 */

/**
 * Candidate file locator.
 */
class FacturxFileLocator
{
	const KIND_CII = 'cii';
	const KIND_UBL = 'ubl';
	const KIND_FACTURX_PDF = 'facturx_pdf';
	const KIND_PDF = 'pdf';

	/** Maximum file size read, matching the API upload limit (10 MiB). */
	const MAX_BYTES = 10485760;

	/**
	 * List candidate files, best first.
	 *
	 * Order: CII XML (the official module's <REF>_cii.xml first), then PDFs that carry an embedded
	 * Factur-X / ZUGFeRD XML, then UBL XML, then plain PDFs (the API will say they are not Factur-X).
	 *
	 * @param string $dir Invoice documents directory
	 * @param string $ref Invoice reference (sanitized as on disk)
	 * @return array<int,array{path:string,name:string,kind:string,size:int,mtime:int}>
	 */
	public static function candidates($dir, $ref)
	{
		$found = array();
		if (!is_dir($dir)) {
			return $found;
		}
		$entries = scandir($dir);
		if ($entries === false) {
			return $found;
		}
		foreach ($entries as $name) {
			if ($name === '.' || $name === '..' || $name[0] === '.') {
				continue;
			}
			$path = $dir.'/'.$name;
			if (!is_file($path) || is_link($path)) {
				continue;
			}
			$size = (int) filesize($path);
			if ($size <= 0 || $size > self::MAX_BYTES) {
				continue;
			}
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			$kind = '';
			if ($ext === 'xml') {
				$kind = self::xmlKind((string) file_get_contents($path, false, null, 0, 4096));
			} elseif ($ext === 'pdf') {
				$kind = self::pdfHasEmbeddedInvoice($path) ? self::KIND_FACTURX_PDF : self::KIND_PDF;
			}
			if ($kind === '') {
				continue;
			}
			$found[] = array('path' => $path, 'name' => $name, 'kind' => $kind, 'size' => $size, 'mtime' => (int) filemtime($path));
		}
		$rank = array(self::KIND_CII => 0, self::KIND_FACTURX_PDF => 1, self::KIND_UBL => 2, self::KIND_PDF => 3);
		usort($found, function ($a, $b) use ($rank, $ref) {
			if ($rank[$a['kind']] !== $rank[$b['kind']]) {
				return $rank[$a['kind']] - $rank[$b['kind']];
			}
			$pa = self::preferredName($a['name'], $ref);
			$pb = self::preferredName($b['name'], $ref);
			if ($pa !== $pb) {
				return $pb - $pa;
			}
			return $b['mtime'] - $a['mtime'];
		});
		return $found;
	}

	/**
	 * @param string $head First bytes of an XML file
	 * @return string KIND_CII, KIND_UBL or '' when it is not an invoice
	 */
	public static function xmlKind($head)
	{
		if (preg_match('/<([A-Za-z0-9_-]+:)?CrossIndustryInvoice[\s>]/', $head)) {
			return self::KIND_CII;
		}
		if (preg_match('/<([A-Za-z0-9_-]+:)?(Invoice|CreditNote)[\s>][^>]*urn:oasis:names:specification:ubl/s', $head)
			|| (preg_match('/urn:oasis:names:specification:ubl:schema:xsd:(Invoice|CreditNote)-2/', $head))) {
			return self::KIND_UBL;
		}
		return '';
	}

	/**
	 * Does the PDF declare an embedded invoice XML (factur-x.xml, zugferd-invoice.xml, xrechnung.xml)?
	 * Looks for the file specification name, which is stored uncompressed in the name tree.
	 *
	 * @param string $path PDF path
	 * @return bool
	 */
	public static function pdfHasEmbeddedInvoice($path)
	{
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return false;
		}
		$found = false;
		$carry = '';
		while (!feof($fh)) {
			$chunk = fread($fh, 262144);
			if ($chunk === false) {
				break;
			}
			$buf = $carry.$chunk;
			if (preg_match('/\((factur-x|zugferd-invoice|ZUGFeRD-invoice|xrechnung|order-x)\.xml\)/i', $buf)
				|| stripos($buf, '<feff006600610063007400750072002d0078002e0078006d006c>') !== false) {
				$found = true;
				break;
			}
			$carry = substr($buf, -64);
		}
		fclose($fh);
		return $found;
	}

	/**
	 * @param string $name File name
	 * @param string $ref  Invoice ref
	 * @return int 2 for <REF>_cii.xml or <REF>.pdf, 1 when it starts with the ref, 0 otherwise
	 */
	private static function preferredName($name, $ref)
	{
		$lower = strtolower($name);
		$r = strtolower($ref);
		if ($r !== '' && ($lower === $r.'_cii.xml' || $lower === $r.'.pdf')) {
			return 2;
		}
		return ($r !== '' && strpos($lower, $r) === 0) ? 1 : 0;
	}
}
