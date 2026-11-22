<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\Availability;
use PHPUnit\Framework\TestCase;

class AvailabilityTest extends TestCase {
	private const NOW = 1800000000;

	/**
	 * @param array<string, mixed> $settings
	 */
	private function state(array $settings, int $responses = 0, int $policyCap = 0): string {
		return Availability::of($settings, self::NOW, $responses, $policyCap)['state'];
	}

	public function testOpenWithoutLimits(): void {
		$this->assertSame(
			['state' => 'open', 'opensAt' => null, 'closesAt' => null, 'cap' => 0, 'responses' => 12],
			Availability::of([], self::NOW, 12, 0),
		);
	}

	public function testScheduledUntilTheOpeningTime(): void {
		$this->assertSame('scheduled', $this->state(['opensAt' => self::NOW + 1]));
		$this->assertSame('open', $this->state(['opensAt' => self::NOW]));
		$this->assertSame('open', $this->state(['opensAt' => self::NOW - 1]));
	}

	public function testEndedFromTheClosingTime(): void {
		$this->assertSame('open', $this->state(['closesAt' => self::NOW + 1]));
		$this->assertSame('ended', $this->state(['closesAt' => self::NOW]));
		$this->assertSame('ended', $this->state(['closesAt' => self::NOW - 1]));
	}

	public function testOpenInsideTheWindow(): void {
		$this->assertSame('open', $this->state(['opensAt' => self::NOW - 60, 'closesAt' => self::NOW + 60]));
	}

	public function testFullAtTheFormsLimit(): void {
		$this->assertSame('open', $this->state(['maxResponses' => 3], 2));
		$this->assertSame('full', $this->state(['maxResponses' => 3], 3));
		$this->assertSame('full', $this->state(['maxResponses' => 3], 4));
	}

	public function testTheLowerOfTheFormsAndTheServersLimitCounts(): void {
		$this->assertSame(5, Availability::of(['maxResponses' => 5], self::NOW, 0, 10)['cap']);
		$this->assertSame(5, Availability::of(['maxResponses' => 10], self::NOW, 0, 5)['cap']);
		$this->assertSame(10, Availability::of(['maxResponses' => 10], self::NOW, 0, 0)['cap']);
		$this->assertSame(5, Availability::of([], self::NOW, 0, 5)['cap']);
		$this->assertSame('full', $this->state(['maxResponses' => 10], 5, 5));
		$this->assertSame('full', $this->state([], 5, 5));
	}

	public function testEndedAndScheduledComeBeforeFull(): void {
		$this->assertSame('ended', $this->state(['maxResponses' => 1, 'closesAt' => self::NOW - 1], 1));
		$this->assertSame('scheduled', $this->state(['maxResponses' => 1, 'opensAt' => self::NOW + 1], 1));
	}

	public function testDamagedValuesAreIgnored(): void {
		$settings = ['maxResponses' => '5', 'opensAt' => '2030-01-01', 'closesAt' => -1];
		$this->assertSame(
			['state' => 'open', 'opensAt' => null, 'closesAt' => null, 'cap' => 0, 'responses' => 99],
			Availability::of($settings, self::NOW, 99, 0),
		);
		$this->assertSame(0, Availability::ownCap(['maxResponses' => 0]));
		$this->assertSame(0, Availability::ownCap(['maxResponses' => Availability::MAX_RESPONSES + 1]));
		$this->assertSame(7, Availability::ownCap(['maxResponses' => 7]));
	}

	public function testWaitingListNeedsALimit(): void {
		$this->assertTrue(Availability::waitsWhenFull(['maxResponses' => 5, 'whenFull' => 'waitlist']));
		$this->assertFalse(Availability::waitsWhenFull(['whenFull' => 'waitlist']));
		$this->assertFalse(Availability::waitsWhenFull(['maxResponses' => 5]));
		$this->assertFalse(Availability::waitsWhenFull(['maxResponses' => 5, 'whenFull' => 'close']));
	}

	public function testOwnLimitCountsOnlyConfirmedResponsesButTheServerLimitCountsAll(): void {
		// 5 confirmed and 3 waiting: the form's own limit of 5 is reached, the server's limit of 10 is not.
		$this->assertSame('full', Availability::of(['maxResponses' => 5], self::NOW, 5, 10, 8)['state']);
		// 2 confirmed and 8 waiting: the form is not full, but the server's limit of 10 is.
		$this->assertSame('full', Availability::of(['maxResponses' => 5], self::NOW, 2, 10, 10)['state']);
		$this->assertSame('open', Availability::of(['maxResponses' => 5], self::NOW, 2, 10, 9)['state']);
	}
}
