<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\CsvFile;
use PHPUnit\Framework\TestCase;

final class CsvFileTest extends TestCase {
	private const BOM = "\xEF\xBB\xBF";

	public function testCleanPath(): void {
		$this->assertSame('Webforms/Leads.csv', CsvFile::cleanPath('/Webforms/Leads.csv'));
		$this->assertSame('leads.CSV', CsvFile::cleanPath('leads.CSV'));
		$this->assertNull(CsvFile::cleanPath('Webforms/Leads.txt'));
		$this->assertNull(CsvFile::cleanPath('.csv'));
		$this->assertNull(CsvFile::cleanPath('Webforms/../Leads.csv'));
		$this->assertNull(CsvFile::cleanPath('Webforms//Leads.csv'));
		$this->assertNull(CsvFile::cleanPath("Web\nforms/Leads.csv"));
		$this->assertNull(CsvFile::cleanPath(''));
	}

	public function testNewFileGetsBomHeaderAndRow(): void {
		$out = CsvFile::append(null, ['Response ID', 'Name'], ['7', 'Ada'], [7, null]);
		$this->assertSame(self::BOM . "Response ID,Name\r\n7,Ada\r\n", $out);
	}

	public function testEmptyFileIsTreatedAsNew(): void {
		$this->assertSame(self::BOM . "A\r\nx\r\n", CsvFile::append(self::BOM, ['A'], ['x'], [null]));
		$this->assertSame(self::BOM . "A\r\nx\r\n", CsvFile::append("  \r\n", ['A'], ['x'], [null]));
	}

	public function testAppendsInTheFilesOwnColumnOrder(): void {
		$existing = self::BOM . "Name,Response ID\r\nGrace,6\r\n";
		$out = CsvFile::append($existing, ['Response ID', 'Name'], ['7', 'Ada'], [7, null]);
		$this->assertSame(self::BOM . "Name,Response ID\r\nGrace,6\r\nAda,7\r\n", $out);
	}

	public function testNewHeadingsGoAtTheEnd(): void {
		$existing = "Response ID,Name,Notes\r\n6,Grace,mine\r\n";
		$out = CsvFile::append($existing, ['Response ID', 'Name', 'Email'], ['7', 'Ada', 'a@example.com'], [7, null, null]);
		$this->assertSame("Response ID,Name,Notes,Email\r\n6,Grace,mine\r\n7,Ada,,a@example.com\r\n", $out);
	}

	public function testMissingFinalLineBreakIsAdded(): void {
		$this->assertSame("A\r\n1\r\n2\r\n", CsvFile::append("A\r\n1", ['A'], ['2'], [2]));
		$this->assertSame("A,B\r\n1\r\n2,x\r\n", CsvFile::append("A\r\n1", ['A', 'B'], ['2', 'x'], [2, null]));
	}

	public function testQuotedHeadingWithLineBreakIsReadAsOne(): void {
		$existing = "\"Two\nlines\",B\r\n1,2\r\n";
		$out = CsvFile::append($existing, ["Two\nlines", 'B'], ['3', '4'], [3, 4]);
		$this->assertSame($existing . "3,4\r\n", $out);
	}

	public function testFormulasAreNeutralizedButNumbersAreNot(): void {
		$out = CsvFile::append(null, ['=Head', 'Value', 'Amount'], ['1', '=cmd()', '-5'], [1, null, -5]);
		$this->assertSame(self::BOM . "'=Head,Value,Amount\r\n1,'=cmd(),-5\r\n", $out);
		// The neutralized heading still matches the next time.
		$this->assertSame($out . "2,x,3\r\n", CsvFile::append($out, ['=Head', 'Value', 'Amount'], ['2', 'x', '3'], [2, null, 3]));
	}

	public function testValuesAreQuoted(): void {
		$out = CsvFile::append("A\r\n", ['A'], ["say \"hi\", ok"], [null]);
		$this->assertSame("A\r\n\"say \"\"hi\"\", ok\"\r\n", $out);
	}
}
