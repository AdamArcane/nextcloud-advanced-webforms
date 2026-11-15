<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\CsvExporter;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase {
	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @return array<string, mixed>
	 */
	private static function definition(array $fields): array {
		return [
			'fields' => $fields,
			'pages' => [['rows' => array_map(static fn (string $id): array => ['cells' => [['field' => $id]]], array_keys($fields))]],
		];
	}

	/**
	 * @return list<list<string>>
	 */
	private static function parse(string $csv): array {
		$body = substr($csv, 3);
		$rows = [];
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, $body);
		rewind($handle);
		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$rows[] = $row;
		}
		return $rows;
	}

	private static function entry(int $id, int $version, array $data, ?string $user = null, int $at = 1794700000): array {
		return ['id' => $id, 'versionId' => $version, 'userId' => $user, 'submittedAt' => $at, 'data' => $data];
	}

	public function testStartsWithAByteOrderMarkAndUsesWindowsLineEndings(): void {
		$csv = CsvExporter::build([], [1 => self::definition(['f_a' => ['type' => 'text', 'label' => 'A']])]);
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
		$this->assertStringEndsWith("\r\n", $csv);
		$this->assertSame([['Response ID', 'Submitted (UTC)', 'Respondent', 'A']], self::parse($csv));
	}

	public function testWritesOneRowPerResponseWithReadableValues(): void {
		$definition = self::definition([
			'f_name' => ['type' => 'text', 'label' => 'Name'],
			'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'new', 'label' => 'New website']]],
			'f_tags' => ['type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']]],
			'f_ok' => ['type' => 'checkbox', 'label' => 'OK'],
			'f_age' => ['type' => 'number', 'label' => 'Age'],
			'f_note' => ['type' => 'paragraph', 'text' => 'not a column'],
		]);
		$csv = CsvExporter::build([
			self::entry(5, 1, ['f_name' => 'Ada', 'f_kind' => 'new', 'f_tags' => ['a', 'b'], 'f_ok' => true, 'f_age' => 36], 'admin'),
			self::entry(6, 1, ['f_name' => 'Bo']),
		], [1 => $definition]);
		$rows = self::parse($csv);
		$this->assertSame(['Response ID', 'Submitted (UTC)', 'Respondent', 'Name', 'Kind', 'Tags', 'OK', 'Age'], $rows[0]);
		$this->assertSame(['5', '2026-11-14 23:46:40', 'admin', 'Ada', 'New website', 'Alpha, Beta', 'Yes', '36'], $rows[1]);
		$this->assertSame(['6', '2026-11-14 23:46:40', '', 'Bo', '', '', '', ''], $rows[2]);
	}

	public function testQuotesCommasQuotesAndNewlines(): void {
		$definition = self::definition(['f_a' => ['type' => 'textarea', 'label' => 'Say "hi", please']]);
		$csv = CsvExporter::build([self::entry(1, 1, ['f_a' => "line one\nline \"two\", ok"])], [1 => $definition]);
		$this->assertStringContainsString('"Say ""hi"", please"', $csv);
		$this->assertSame("line one\nline \"two\", ok", self::parse($csv)[1][3]);
	}

	public function testFormulasInAnswersAreDefused(): void {
		$definition = self::definition(['f_a' => ['type' => 'text', 'label' => 'A']]);
		foreach (['=1+1', '+1+1', '-1+1', '@SUM(A1)', "\t=1", "\r=1", '=HYPERLINK("http://evil","x")'] as $evil) {
			$cell = self::parse(CsvExporter::build([self::entry(1, 1, ['f_a' => $evil])], [1 => $definition]))[1][3];
			$this->assertStringStartsWith("'", $cell, $evil);
			$this->assertSame("'" . $evil, $cell, $evil);
		}
	}

	public function testOrdinaryTextAndNumbersAreNotAltered(): void {
		$definition = self::definition([
			'f_a' => ['type' => 'text', 'label' => 'A'],
			'f_n' => ['type' => 'number', 'label' => 'N'],
		]);
		$row = self::parse(CsvExporter::build([self::entry(1, 1, ['f_a' => 'a-b = c', 'f_n' => -5.5])], [1 => $definition]))[1];
		$this->assertSame('a-b = c', $row[3]);
		$this->assertSame('-5.5', $row[4], 'a negative number is data, not text');
	}

	public function testHeadersAreDefusedToo(): void {
		$rows = self::parse(CsvExporter::build([], [1 => self::definition(['f_a' => ['type' => 'text', 'label' => '=cmd|calc']])]));
		$this->assertSame("'=cmd|calc", $rows[0][3]);
	}

	public function testNeutralizeOnlyTouchesALeadingTrigger(): void {
		$this->assertSame("'=x", CsvExporter::neutralize('=x'));
		$this->assertSame('x=', CsvExporter::neutralize('x='));
		$this->assertSame('', CsvExporter::neutralize(''));
	}

	public function testColumnsAreTheUnionOfVersionsNewestFirstAndEachRowUsesItsOwnVersion(): void {
		$v1 = self::definition([
			'f_name' => ['type' => 'text', 'label' => 'Name'],
			'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'x', 'label' => 'Old label']]],
		]);
		$v2 = self::definition([
			'f_name' => ['type' => 'text', 'label' => 'Full name'],
			'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'x', 'label' => 'New label']]],
			'f_phone' => ['type' => 'phone', 'label' => 'Phone'],
		]);
		$rows = self::parse(CsvExporter::build([
			self::entry(1, 1, ['f_name' => 'Ada', 'f_kind' => 'x']),
			self::entry(2, 2, ['f_name' => 'Bo', 'f_kind' => 'x', 'f_phone' => '+1 555 0100']),
		], [1 => $v1, 2 => $v2]));
		// Newest version's labels and order win; the column that only exists in v2 is still there.
		$this->assertSame(['Response ID', 'Submitted (UTC)', 'Respondent', 'Full name', 'Kind', 'Phone'], $rows[0]);
		// The first response keeps the option label it was submitted with.
		$this->assertSame('Old label', $rows[1][4]);
		$this->assertSame('New label', $rows[2][4]);
		$this->assertSame('', $rows[1][5]);
		$this->assertSame("'+1 555 0100", $rows[2][5]);
	}

	public function testAFieldRemovedInALaterVersionKeepsItsColumn(): void {
		$v1 = self::definition(['f_old' => ['type' => 'text', 'label' => 'Old'], 'f_keep' => ['type' => 'text', 'label' => 'Keep']]);
		$v2 = self::definition(['f_keep' => ['type' => 'text', 'label' => 'Keep']]);
		$rows = self::parse(CsvExporter::build([self::entry(1, 1, ['f_old' => 'o', 'f_keep' => 'k'])], [1 => $v1, 2 => $v2]));
		$this->assertSame(['Response ID', 'Submitted (UTC)', 'Respondent', 'Keep', 'Old'], $rows[0]);
		$this->assertSame(['k', 'o'], array_slice($rows[1], 3));
	}

	public function testDifferentFieldsWithTheSameLabelGetDistinctHeaders(): void {
		$v1 = self::definition(['f_one' => ['type' => 'text', 'label' => 'Email']]);
		$v2 = self::definition(['f_two' => ['type' => 'text', 'label' => 'Email']]);
		$rows = self::parse(CsvExporter::build([], [1 => $v1, 2 => $v2]));
		$this->assertSame(['Email (f_two)', 'Email (f_one)'], array_slice($rows[0], 3));
	}

	public function testKeepsNonAsciiText(): void {
		$definition = self::definition(['f_a' => ['type' => 'text', 'label' => 'Nom']]);
		$cell = self::parse(CsvExporter::build([self::entry(1, 1, ['f_a' => 'Zoë 日本語 🙂'])], [1 => $definition]))[1][3];
		$this->assertSame('Zoë 日本語 🙂', $cell);
	}

	public function testUploadedFilesAreListedByName(): void {
		$definitions = [1 => self::definition(['f_cv' => ['type' => 'file', 'label' => 'CV']])];
		$entries = [['id' => 1, 'versionId' => 1, 'userId' => null, 'submittedAt' => 0, 'data' => ['f_cv' => [
			['name' => 'cv.pdf', 'size' => 1, 'mime' => 'application/pdf', 'storage' => 'files', 'ref' => '9'],
			['name' => '=cmd.pdf', 'size' => 1, 'mime' => 'application/pdf', 'storage' => 'app', 'ref' => 'abc'],
		]]]];
		$rows = self::parse(CsvExporter::build($entries, $definitions));
		// Only a cell that starts with a formula character is neutralised, and this one starts with a letter.
		$this->assertSame('cv.pdf, =cmd.pdf', $rows[1][3]);
	}
}
