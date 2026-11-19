<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Access;

use OCA\Webforms\Access\NextOwner;
use PHPUnit\Framework\TestCase;

final class NextOwnerTest extends TestCase {
	private const USER = 0;
	private const GROUP = 1;

	public function testTheEditorAddedFirstWhoMayOwnTakesTheForm(): void {
		$shares = [
			['type' => self::USER, 'with' => 'rita', 'role' => 'reviewer'],
			['type' => self::USER, 'with' => 'eve', 'role' => 'editor'],
			['type' => self::USER, 'with' => 'ed', 'role' => 'editor'],
		];
		self::assertSame('eve', NextOwner::pick($shares, self::USER, static fn (): bool => true));
	}

	public function testAnEditorWhoMayNotCreateFormsOrIsGoneIsSkipped(): void {
		$shares = [
			['type' => self::USER, 'with' => 'eve', 'role' => 'editor'],
			['type' => self::USER, 'with' => 'ed', 'role' => 'editor'],
		];
		self::assertSame('ed', NextOwner::pick($shares, self::USER, static fn (string $id): bool => $id === 'ed'));
	}

	public function testGroupsAndReviewersNeverTakeTheForm(): void {
		$shares = [
			['type' => self::GROUP, 'with' => 'staff', 'role' => 'editor'],
			['type' => self::USER, 'with' => 'rita', 'role' => 'reviewer'],
		];
		self::assertNull(NextOwner::pick($shares, self::USER, static fn (): bool => true));
	}

	public function testNoSharesLeavesTheFormToBeDeleted(): void {
		self::assertNull(NextOwner::pick([], self::USER, static fn (): bool => true));
	}
}
