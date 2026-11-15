<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\ActionPlanner;
use OCA\Webforms\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class ActionPlannerTest extends TestCase {
	/**
	 * @param list<array<string, mixed>> $actions
	 * @return array<string, mixed>
	 */
	private function definition(array $actions): array {
		return [
			'fields' => [
				'f_name' => ['type' => 'text', 'label' => 'Name'],
				'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'new', 'label' => 'New'], ['value' => 'support', 'label' => 'Support']]],
			],
			'actions' => $actions,
		];
	}

	private function plan(array $actions, array $data): array {
		return (new ActionPlanner(new RuleEngine()))->plan($this->definition($actions), $data);
	}

	public function testNothingToDoWithoutActions(): void {
		$this->assertSame(['message' => null, 'redirect' => null, 'queue' => []], $this->plan([], ['f_name' => 'Ada']));
	}

	public function testMessageIsResolvedNowWithPlaceholders(): void {
		$plan = $this->plan([['id' => 'act_one', 'type' => 'message', 'text' => 'Thanks {f_name}!']], ['f_name' => 'Ada']);
		$this->assertSame('Thanks Ada!', $plan['message']);
	}

	public function testRedirectEncodesValues(): void {
		$plan = $this->plan([['id' => 'act_one', 'type' => 'redirect', 'url' => 'https://example.com/ok?n={f_name}']], ['f_name' => 'A B']);
		$this->assertSame('https://example.com/ok?n=A%20B', $plan['redirect']);
		$this->assertNull($plan['message']);
	}

	public function testEmailAndWebhookAreQueuedInOrderNotRun(): void {
		$plan = $this->plan([
			['id' => 'act_mail', 'type' => 'email'],
			['id' => 'act_hook', 'type' => 'webhook'],
		], []);
		$this->assertSame(['act_mail', 'act_hook'], $plan['queue']);
	}

	public function testFirstMatchingMessageOrRedirectWins(): void {
		$plan = $this->plan([
			['id' => 'act_one', 'type' => 'redirect', 'url' => 'https://example.com/a'],
			['id' => 'act_two', 'type' => 'message', 'text' => 'ignored'],
			['id' => 'act_three', 'type' => 'redirect', 'url' => 'https://example.com/b'],
		], []);
		$this->assertSame('https://example.com/a', $plan['redirect']);
		$this->assertNull($plan['message']);

		$plan = $this->plan([
			['id' => 'act_one', 'type' => 'message', 'text' => 'first'],
			['id' => 'act_two', 'type' => 'redirect', 'url' => 'https://example.com/b'],
		], []);
		$this->assertSame('first', $plan['message']);
		$this->assertNull($plan['redirect']);
	}

	public function testConditionsDecideWhichActionsApply(): void {
		$actions = [
			['id' => 'act_support', 'type' => 'webhook', 'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'support']]]],
			['id' => 'act_new', 'type' => 'message', 'text' => 'Welcome', 'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'new']]]],
			['id' => 'act_any', 'type' => 'email'],
		];
		$support = $this->plan($actions, ['f_kind' => 'support']);
		$this->assertSame(['act_support', 'act_any'], $support['queue']);
		$this->assertNull($support['message']);

		$new = $this->plan($actions, ['f_kind' => 'new']);
		$this->assertSame(['act_any'], $new['queue']);
		$this->assertSame('Welcome', $new['message']);
	}

	public function testAConditionOnAnUnansweredFieldDoesNotMatch(): void {
		$plan = $this->plan([['id' => 'act_one', 'type' => 'message', 'text' => 'x', 'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'new']]]]], []);
		$this->assertNull($plan['message']);
	}

	public function testABrokenConditionNeverMatches(): void {
		$plan = $this->plan([['id' => 'act_one', 'type' => 'webhook', 'when' => 'nonsense']], []);
		$this->assertSame([], $plan['queue']);
	}

	public function testAMessageSkippedByItsConditionLeavesTheSlotFree(): void {
		$plan = $this->plan([
			['id' => 'act_one', 'type' => 'message', 'text' => 'no', 'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'new']]]],
			['id' => 'act_two', 'type' => 'message', 'text' => 'yes'],
		], ['f_kind' => 'support']);
		$this->assertSame('yes', $plan['message']);
	}

	public function testARedirectThatTemplatingMadeUnsafeIsDropped(): void {
		// A scheme in the template position cannot be reached through an answer, but be sure.
		$plan = $this->plan([['id' => 'act_one', 'type' => 'redirect', 'url' => 'http://example.com/x']], []);
		$this->assertNull($plan['redirect']);
		$this->assertFalse(ActionPlanner::isSafeRedirect('javascript:alert(1)'));
		$this->assertFalse(ActionPlanner::isSafeRedirect('https://example.com/ a'));
		$this->assertFalse(ActionPlanner::isSafeRedirect("https://example.com/\r\nX: y"));
		$this->assertTrue(ActionPlanner::isSafeRedirect('https://example.com:8443/a?b=c#d'));
	}
}
