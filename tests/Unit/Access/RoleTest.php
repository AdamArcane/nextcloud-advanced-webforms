<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Access;

use OCA\Webforms\Access\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase {
	public function testAHigherRoleMayDoWhatALowerOneMay(): void {
		self::assertTrue(Role::OWNER->atLeast(Role::EDITOR));
		self::assertTrue(Role::EDITOR->atLeast(Role::REVIEWER));
		self::assertTrue(Role::EDITOR->atLeast(Role::EDITOR));
		self::assertFalse(Role::REVIEWER->atLeast(Role::EDITOR));
		self::assertFalse(Role::EDITOR->atLeast(Role::OWNER));
	}

	public function testTheOwnerIsTheOwnerWhateverIsSharedWithThem(): void {
		self::assertSame(Role::OWNER, Role::resolve(true, []));
		self::assertSame(Role::OWNER, Role::resolve(true, ['reviewer']));
	}

	public function testSomeoneWithNoShareHasNoRole(): void {
		self::assertNull(Role::resolve(false, []));
	}

	public function testTheHighestOfTheirSharesWins(): void {
		self::assertSame(Role::EDITOR, Role::resolve(false, ['reviewer', 'editor']));
		self::assertSame(Role::EDITOR, Role::resolve(false, ['editor', 'reviewer']));
		self::assertSame(Role::REVIEWER, Role::resolve(false, ['reviewer', 'reviewer']));
	}

	public function testAShareNeverMakesAnOwner(): void {
		self::assertNull(Role::resolve(false, ['owner']));
		self::assertNull(Role::forShare('owner'));
		self::assertNull(Role::forShare('admin'));
		self::assertNull(Role::forShare(''));
	}

	public function testAnUnknownRoleInTheTableGivesNothing(): void {
		self::assertNull(Role::resolve(false, ['viewer']));
		self::assertSame(Role::REVIEWER, Role::resolve(false, ['viewer', 'reviewer']));
	}

	public function testRolesHaveStableNames(): void {
		self::assertSame('owner', Role::OWNER->key());
		self::assertSame('editor', Role::EDITOR->key());
		self::assertSame('reviewer', Role::REVIEWER->key());
	}
}
