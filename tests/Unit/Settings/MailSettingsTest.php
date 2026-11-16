<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Settings;

use OCA\Webforms\Actions\MailPolicy;
use OCA\Webforms\Security\Captcha\ConfigStore;
use OCA\Webforms\Settings\MailSettings;
use OCA\Webforms\Settings\PolicyCheck;
use PHPUnit\Framework\TestCase;

final class MailSettingsTest extends TestCase {
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

	public function testNeverConfiguredKeepsTodaysBehaviour(): void {
		$policy = (new MailSettings($this->store()))->policy();
		$this->assertSame(MailPolicy::REPLY_NONE, $policy->replyToMode);
		$this->assertSame('', $policy->replyToAddress);
		$this->assertTrue($policy->allowOverride);
		$this->assertSame('', $policy->fromName);
	}

	public function testSavesAndReadsBack(): void {
		$settings = new MailSettings($this->store());
		$settings->save(MailPolicy::REPLY_ADDRESS, ' support@example.com ', false, "  Acme\r\nSupport ");
		$policy = $settings->policy();
		$this->assertSame(MailPolicy::REPLY_ADDRESS, $policy->replyToMode);
		$this->assertSame('support@example.com', $policy->replyToAddress);
		$this->assertFalse($policy->allowOverride);
		$this->assertSame('Acme Support', $policy->fromName);

		// The address only belongs to the fixed-address choice.
		$settings->save(MailPolicy::REPLY_OWNER, 'support@example.com', true, '');
		$this->assertSame(MailPolicy::REPLY_OWNER, $settings->policy()->replyToMode);
		$this->assertSame('', $settings->policy()->replyToAddress);
	}

	public function testDamagedValuesFallBackToTheSafeSide(): void {
		$store = $this->store();
		$store->set('mail_reply_to_mode', 'everyone');
		$store->set('mail_allow_override', 'maybe');
		$policy = (new MailSettings($store))->policy();
		$this->assertSame(MailPolicy::REPLY_NONE, $policy->replyToMode);
		$this->assertTrue($policy->allowOverride);

		$store->set('mail_reply_to_mode', MailPolicy::REPLY_ADDRESS);
		$store->set('mail_reply_to_address', "a@example.com\r\nBcc: evil@example.com");
		$policy = (new MailSettings($store))->policy();
		$this->assertSame(MailPolicy::REPLY_NONE, $policy->replyToMode);
		$this->assertSame('', $policy->replyToAddress);
	}

	public function testANameIsOneLineAndNotTooLong(): void {
		$this->assertSame('Acme Support', MailPolicy::cleanName("  Acme\tSupport\n"));
		$this->assertSame(MailPolicy::MAX_NAME, mb_strlen(MailPolicy::cleanName(str_repeat('é', 300))));
	}

	public function testAnActionsOwnChoicesCountUnlessLocked(): void {
		$action = ['type' => 'email', 'replyTo' => 'desk@example.com', 'fromName' => 'Desk'];
		$open = new MailPolicy(fromName: 'Acme');
		$this->assertSame('desk@example.com', $open->replyToSource($action));
		$this->assertSame('Desk', $open->fromNameFor($action));
		$this->assertSame('Acme', $open->fromNameFor(['type' => 'email']));
		$this->assertNull((new MailPolicy())->fromNameFor(['type' => 'email']));
		$this->assertSame([], $open->lockedProperties($action));

		$locked = new MailPolicy(allowOverride: false, fromName: 'Acme');
		$this->assertNull($locked->replyToSource($action));
		$this->assertSame('Acme', $locked->fromNameFor($action));
		$this->assertSame(['replyTo', 'fromName'], $locked->lockedProperties($action));
		// Replying to the person who filled in the form is always allowed.
		$this->assertSame(['field' => 'f_email'], $locked->replyToSource(['replyTo' => ['field' => 'f_email']]));
		$this->assertSame([], $locked->lockedProperties(['type' => 'email', 'replyTo' => ['field' => 'f_email']]));
	}

	public function testALockedPolicyIsReportedPerEmailAction(): void {
		$definition = ['actions' => [
			['id' => 'a', 'type' => 'message', 'text' => 'x'],
			['id' => 'b', 'type' => 'email', 'to' => 'x@example.com', 'replyTo' => ['owner' => true], 'fromName' => 'Desk'],
			['id' => 'c', 'type' => 'email', 'to' => 'x@example.com', 'replyTo' => ['field' => 'f_email']],
		]];
		$this->assertSame([], PolicyCheck::mailErrors($definition, new MailPolicy()));
		$this->assertSame([
			['code' => 'action_mail_locked', 'path' => 'actions[1].replyTo'],
			['code' => 'action_mail_locked', 'path' => 'actions[1].fromName'],
		], PolicyCheck::mailErrors($definition, new MailPolicy(allowOverride: false)));
	}
}
