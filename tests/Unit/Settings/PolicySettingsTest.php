<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Settings;

use OCA\Webforms\Security\Captcha\ConfigStore;
use OCA\Webforms\Settings\PolicyCheck;
use OCA\Webforms\Settings\PolicySettings;
use PHPUnit\Framework\TestCase;

final class PolicySettingsTest extends TestCase {
	private function store(): ConfigStore {
		return new class implements ConfigStore {
			/** @var array<string, string> */
			public array $values = [];

			public function get(string $key, bool $sensitive = false): string {
				return $this->values[$key] ?? '';
			}

			public function set(string $key, string $value, bool $sensitive = false): void {
				$this->values[$key] = $value;
			}
		};
	}

	public function testDefaultsKeepTodaysBehaviour(): void {
		$policy = new PolicySettings($this->store());
		$this->assertSame(['email', 'webhook', 'message', 'redirect', 'tables', 'files'], $policy->allowedActionTypes());
		$this->assertSame(0, $policy->retentionDays());
		$this->assertSame(0, $policy->maxResponsesPerForm());
		$this->assertSame(10, $policy->maxActionsPerForm());
		$this->assertSame([], $policy->creatorGroups());
		$this->assertTrue($policy->sharingEnabled());
	}

	public function testSaveAndReadBack(): void {
		$policy = new PolicySettings($this->store());
		$policy->save(['message', 'redirect', 'email'], 90, 500, 4, ['staff', 'admin', 'staff', '']);
		$this->assertSame(['email', 'message', 'redirect'], $policy->allowedActionTypes());
		$this->assertSame(90, $policy->retentionDays());
		$this->assertSame(500, $policy->maxResponsesPerForm());
		$this->assertSame(4, $policy->maxActionsPerForm());
		$this->assertSame(['staff', 'admin'], $policy->creatorGroups());
	}

	public function testSharingCanBeSwitchedOffAndOnAgain(): void {
		$policy = new PolicySettings($this->store());
		$policy->save(['message'], 0, 0, 10, [], false);
		$this->assertFalse($policy->sharingEnabled());
		$policy->save(['message'], 0, 0, 10, [], true);
		$this->assertTrue($policy->sharingEnabled());
	}

	public function testAllTypesIsStoredAsNothingSoNewTypesStayAllowed(): void {
		$store = $this->store();
		$policy = new PolicySettings($store);
		$policy->save(['email', 'webhook', 'message', 'redirect', 'tables', 'files'], 0, 0, 10, []);
		$this->assertSame('', $store->get('policy_action_types'));
		$this->assertCount(6, $policy->allowedActionTypes());
	}

	public function testNoActionsAtAllIsAllowed(): void {
		$policy = new PolicySettings($this->store());
		$policy->save([], 0, 0, 10, []);
		$this->assertSame([], $policy->allowedActionTypes());
	}

	public function testValuesAreClamped(): void {
		$policy = new PolicySettings($this->store());
		$policy->save(['email'], 999999, 99999999, 99, []);
		$this->assertSame(PolicySettings::MAX_RETENTION_DAYS, $policy->retentionDays());
		$this->assertSame(PolicySettings::MAX_RESPONSES_LIMIT, $policy->maxResponsesPerForm());
		$this->assertSame(10, $policy->maxActionsPerForm());
		$policy->save(['email'], -5, -1, 0, []);
		$this->assertSame(0, $policy->retentionDays());
		$this->assertSame(0, $policy->maxResponsesPerForm());
		$this->assertSame(1, $policy->maxActionsPerForm());
	}

	public function testDamagedStoredValuesFallBackToDefaults(): void {
		$store = $this->store();
		$store->set('policy_action_types', '{not json');
		$store->set('policy_retention_days', 'soon');
		$store->set('policy_max_responses', '-4');
		$store->set('policy_max_actions', '999');
		$store->set('policy_creator_groups', '"x"');
		$policy = new PolicySettings($store);
		// An unreadable action list reads as "none allowed", never as a crash.
		$this->assertSame([], $policy->allowedActionTypes());
		$this->assertSame(0, $policy->retentionDays());
		$this->assertSame(0, $policy->maxResponsesPerForm());
		$this->assertSame(10, $policy->maxActionsPerForm());
		$this->assertSame([], $policy->creatorGroups());
	}

	public function testUnknownActionTypesAreDropped(): void {
		$store = $this->store();
		$store->set('policy_action_types', '["email","carrier_pigeon"]');
		$this->assertSame(['email'], (new PolicySettings($store))->allowedActionTypes());
	}

	public function testActionErrors(): void {
		$definition = ['actions' => [['id' => 'a', 'type' => 'email'], ['id' => 'b', 'type' => 'webhook'], ['id' => 'c', 'type' => 'tables']]];
		$this->assertSame([], PolicyCheck::actionErrors($definition, ['email', 'webhook', 'tables'], 10));
		$this->assertSame(
			[['code' => 'action_type_disabled', 'path' => 'actions[1]']],
			PolicyCheck::actionErrors($definition, ['email', 'tables'], 10),
		);
		$this->assertSame(
			[['code' => 'policy_too_many_actions', 'path' => 'actions']],
			PolicyCheck::actionErrors($definition, ['email', 'webhook', 'tables'], 2),
		);
		$this->assertSame([], PolicyCheck::actionErrors(['actions' => []], [], 1));
	}

	public function testWithoutDisabledActionsKeepsTheRestInOrder(): void {
		$definition = ['title' => 'T', 'actions' => [['id' => 'a', 'type' => 'webhook'], ['id' => 'b', 'type' => 'email'], ['id' => 'c', 'type' => 'message']]];
		$result = PolicyCheck::withoutDisabledActions($definition, ['email', 'message']);
		$this->assertSame(['b', 'c'], array_column($result['actions'], 'id'));
		$this->assertSame('T', $result['title']);
		$this->assertSame(['title' => 'T'], PolicyCheck::withoutDisabledActions(['title' => 'T'], []));
	}

	public function testRetentionCutoff(): void {
		$this->assertNull(PolicyCheck::retentionCutoff(1000000, 0));
		$this->assertSame(1000000 - 30 * 86400, PolicyCheck::retentionCutoff(1000000, 30));
	}
}
