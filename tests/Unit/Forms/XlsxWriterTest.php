<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\CsvExporter;
use OCA\Webforms\Forms\XlsxWriter;
use PHPUnit\Framework\TestCase;

final class XlsxWriterTest extends TestCase {
	/**
	 * @return array<string, string> file name => contents
	 */
	private static function unzip(string $bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'wft');
		file_put_contents($path, $bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path));
		$files = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$files[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		unlink($path);
		return $files;
	}

	public function testIsAWorkbookWithTheExpectedParts(): void {
		$files = self::unzip(XlsxWriter::build(['A', 'B'], [['x', 'y']]));
		foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
			$this->assertArrayHasKey($part, $files);
			$this->assertNotFalse(simplexml_load_string($files[$part]), $part . ' is well formed');
		}
		$this->assertStringContainsString('name="Responses"', $files['xl/workbook.xml']);
	}

	public function testTextIsStoredAsTextAndNumbersAsNumbers(): void {
		$sheet = self::unzip(XlsxWriter::build(['Name', 'Score'], [['Ada', '4.5'], ['Bo', '']], [[null, 4.5], [null, null]]))['xl/worksheets/sheet1.xml'];
		$this->assertStringContainsString('<c r="A2" t="inlineStr"><is><t xml:space="preserve">Ada</t></is></c>', $sheet);
		$this->assertStringContainsString('<c r="B2"><v>4.5</v></c>', $sheet);
		// The header is bold text, and an empty cell is simply absent.
		$this->assertStringContainsString('<c r="A1" t="inlineStr" s="1">', $sheet);
		$this->assertStringNotContainsString('r="B3"', $sheet);
	}

	public function testAResponseThatLooksLikeAFormulaStaysText(): void {
		$sheet = self::unzip(XlsxWriter::build(['Note'], [['=HYPERLINK("http://evil","x")'], ['+1+1'], ['@SUM(A1)']]))['xl/worksheets/sheet1.xml'];
		$this->assertStringNotContainsString('<f>', $sheet);
		$this->assertSame(3, substr_count($sheet, 't="inlineStr"') - 1);
		$this->assertStringContainsString('=HYPERLINK(&quot;http://evil&quot;,&quot;x&quot;)', $sheet);
		// No leading apostrophe either: that is for CSV, where it is the only defence.
		$this->assertStringNotContainsString(">'=", $sheet);
	}

	public function testXmlSpecialCharactersAndControlCharactersAreHandled(): void {
		$sheet = self::unzip(XlsxWriter::build(['H'], [["a<b>&\"c'\x01\x0B d\u{1F600}\ne"]]))['xl/worksheets/sheet1.xml'];
		$this->assertNotFalse(simplexml_load_string($sheet));
		$this->assertStringContainsString('a&lt;b&gt;&amp;&quot;c&apos;', $sheet);
		$this->assertStringNotContainsString("\x01", $sheet);
		$this->assertStringContainsString("\u{1F600}", $sheet);
	}

	public function testLongTextIsCutAtExcelsLimit(): void {
		$sheet = self::unzip(XlsxWriter::build(['H'], [[str_repeat('x', XlsxWriter::MAX_CELL + 500)]]))['xl/worksheets/sheet1.xml'];
		$this->assertSame(1, preg_match('/<t xml:space="preserve">(x+)<\/t>/', $sheet, $match));
		$this->assertSame(XlsxWriter::MAX_CELL, strlen($match[1]));
	}

	public function testColumnNames(): void {
		$this->assertSame('A', XlsxWriter::column(0));
		$this->assertSame('Z', XlsxWriter::column(25));
		$this->assertSame('AA', XlsxWriter::column(26));
		$this->assertSame('AZ', XlsxWriter::column(51));
		$this->assertSame('BA', XlsxWriter::column(52));
		$this->assertSame('ZZ', XlsxWriter::column(701));
		$this->assertSame('AAA', XlsxWriter::column(702));
	}

	public function testAWholeExportBecomesAWorkbook(): void {
		$definition = ['fields' => ['f_name' => ['type' => 'text', 'label' => 'Name'], 'f_size' => ['type' => 'number', 'label' => 'Size']], 'pages' => [['rows' => [['cells' => [['field' => 'f_name']]], ['cells' => [['field' => 'f_size']]]]]]];
		$table = CsvExporter::table([['id' => 7, 'versionId' => 1, 'userId' => 'ada', 'submittedAt' => 1794700000, 'data' => ['f_name' => '=cmd', 'f_size' => 42]]], [1 => $definition]);
		$sheet = self::unzip(XlsxWriter::build($table['header'], $table['rows'], $table['numbers']))['xl/worksheets/sheet1.xml'];
		$this->assertStringContainsString('<c r="A2"><v>7</v></c>', $sheet);
		$this->assertStringContainsString('2026-11-14 23:46:40', $sheet);
		$this->assertStringContainsString('>ada<', $sheet);
		$this->assertStringContainsString('=cmd', $sheet);
		$this->assertStringContainsString('<c r="E2"><v>42</v></c>', $sheet);
	}

	public function testTheTableCanBeNarrowedToChosenColumns(): void {
		$definition = ['fields' => ['f_a' => ['type' => 'text', 'label' => 'A'], 'f_b' => ['type' => 'text', 'label' => 'B'], 'f_c' => ['type' => 'text', 'label' => 'C']], 'pages' => [['rows' => [['cells' => [['field' => 'f_a']]], ['cells' => [['field' => 'f_b']]], ['cells' => [['field' => 'f_c']]]]]]];
		$entries = [['id' => 1, 'versionId' => 1, 'userId' => null, 'submittedAt' => 1794700000, 'data' => ['f_a' => '1', 'f_b' => '2', 'f_c' => '3']]];
		$this->assertSame(['Response ID', 'Submitted (UTC)', 'Respondent', 'A', 'C'], CsvExporter::table($entries, [1 => $definition], 'Submitted (UTC)', ['f_c', 'f_a'])['header']);
		$this->assertSame(['Response ID', 'Submitted (UTC)', 'Respondent', 'A', 'B', 'C'], CsvExporter::table($entries, [1 => $definition])['header']);
		$this->assertSame(['Response ID', 'Submitted (UTC)', 'Respondent'], CsvExporter::table($entries, [1 => $definition], 'Submitted (UTC)', [])['header']);
	}
}
