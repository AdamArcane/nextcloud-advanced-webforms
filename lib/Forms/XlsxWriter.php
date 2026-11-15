<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

/**
 * Writes one worksheet as an .xlsx workbook, with no dependencies beyond PHP's zip extension. Text is
 * stored as text, never as a formula, so a response that starts with = or + cannot run anything in the
 * spreadsheet, and numbers stay numbers so they can be summed. Pure apart from a temporary file.
 */
final class XlsxWriter {
	/** Excel's limit for the text of one cell. */
	public const MAX_CELL = 32767;
	public const SHEET_NAME = 'Responses';

	/**
	 * @param list<string> $header
	 * @param list<list<string>> $rows
	 * @param list<list<int|float|null>> $numbers the same shape as $rows: the number a cell holds, or null for text
	 * @throws \RuntimeException when the workbook cannot be assembled
	 */
	public static function build(array $header, array $rows, array $numbers = []): string {
		$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>'
			. self::row(1, $header, [], true);
		foreach ($rows as $index => $row) {
			$sheet .= self::row($index + 2, $row, $numbers[$index] ?? [], false);
		}
		$sheet .= '</sheetData></worksheet>';

		$files = [
			'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
				. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
				. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
			'_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::SHEET_NAME . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
			// Style 0 is plain, style 1 is bold (the header).
			'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
				. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
				. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
			'xl/worksheets/sheet1.xml' => $sheet,
		];

		$path = tempnam(sys_get_temp_dir(), 'wfx');
		if ($path === false) {
			throw new \RuntimeException('No temporary file');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
				throw new \RuntimeException('Could not create the workbook');
			}
			foreach ($files as $name => $content) {
				$zip->addFromString($name, $content);
			}
			if (!$zip->close()) {
				throw new \RuntimeException('Could not write the workbook');
			}
			$bytes = file_get_contents($path);
			if ($bytes === false) {
				throw new \RuntimeException('Could not read the workbook');
			}
			return $bytes;
		} finally {
			@unlink($path);
		}
	}

	/**
	 * @param list<string> $cells
	 * @param list<int|float|null> $numbers
	 */
	private static function row(int $number, array $cells, array $numbers, bool $bold): string {
		$xml = '<row r="' . $number . '">';
		foreach (array_values($cells) as $index => $text) {
			$ref = self::column($index) . $number;
			$value = $numbers[$index] ?? null;
			if ($value !== null && !$bold && is_finite((float)$value)) {
				$xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
			} elseif ($text !== '') {
				$xml .= '<c r="' . $ref . '" t="inlineStr"' . ($bold ? ' s="1"' : '') . '><is><t xml:space="preserve">' . self::escape($text) . '</t></is></c>';
			}
		}
		return $xml . '</row>';
	}

	/**
	 * A, B, ... Z, AA, AB, ...
	 */
	public static function column(int $index): string {
		$name = '';
		for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
			$name = chr(65 + $n % 26) . $name;
		}
		return $name;
	}

	/**
	 * Characters XML cannot hold are dropped, the rest is escaped, and the text is cut at Excel's limit.
	 */
	private static function escape(string $text): string {
		$text = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
		return htmlspecialchars(mb_substr($text, 0, self::MAX_CELL), ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
