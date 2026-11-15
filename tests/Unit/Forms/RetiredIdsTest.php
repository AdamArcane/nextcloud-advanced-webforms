<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\RetiredIds;
use PHPUnit\Framework\TestCase;

class RetiredIdsTest extends TestCase {
	public function testNothingIsRetiredWhenNothingIsRemoved(): void {
		$this->assertSame([], RetiredIds::after([], ['f_a', 'f_b'], ['f_a', 'f_b', 'f_c'], ['f_a']));
	}

	public function testARemovedIdThatWasNeverPublishedIsFreeToUseAgain(): void {
		// Renaming while building: f_old existed only in the draft.
		$this->assertSame([], RetiredIds::after([], ['f_old'], ['f_new'], []));
		$this->assertSame([], RetiredIds::after([], ['f_old'], ['f_new'], ['f_other']));
	}

	public function testARemovedIdThatWasPublishedIsReserved(): void {
		$this->assertSame(['f_old'], RetiredIds::after([], ['f_old'], ['f_new'], ['f_old']));
	}

	public function testOnlyThePublishedOnesAmongSeveralRemovedAreReserved(): void {
		$this->assertSame(['f_pub'], RetiredIds::after([], ['f_pub', 'f_draft', 'f_keep'], ['f_keep'], ['f_pub', 'f_keep']));
	}

	public function testAlreadyReservedIdsStayReservedWithoutDuplicates(): void {
		$this->assertSame(['f_gone', 'f_old'], RetiredIds::after(['f_gone'], ['f_old'], [], ['f_old', 'f_gone']));
		$this->assertSame(['f_old'], RetiredIds::after(['f_old'], ['f_old'], [], ['f_old']));
	}

	public function testIdsThatLookLikeNumbersAreComparedAsStrings(): void {
		// PHP turns numeric array keys into integers; ids must still match.
		$this->assertSame(['123'], RetiredIds::after([], ['123'], [], ['123']));
	}
}
