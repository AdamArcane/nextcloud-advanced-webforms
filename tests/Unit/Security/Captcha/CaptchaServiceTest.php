<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Security\Captcha;

use OCA\Webforms\Security\Captcha\CaptchaProvider;
use OCA\Webforms\Security\Captcha\CaptchaService;
use OCA\Webforms\Security\Captcha\CaptchaSettings;
use OCA\Webforms\Security\Captcha\ProofOfWork;
use OCA\Webforms\Security\Captcha\RemoteCaptcha;
use OCA\Webforms\Security\Captcha\ConfigStore;
use PHPUnit\Framework\TestCase;

class CaptchaServiceTest extends TestCase {
	/**
	 * @param array<string, string> $config
	 */
	private function service(array $config): CaptchaService {
		$store = new class($config) implements ConfigStore {
			/** @param array<string, string> $values */
			public function __construct(private array $values) {
			}

			public function get(string $key, bool $sensitive = false): string {
				return $this->values[$key] ?? '';
			}

			public function set(string $key, string $value, bool $sensitive = false): void {
				$this->values[$key] = $value;
			}
		};
		return new CaptchaService(
			new CaptchaSettings($store),
			new ProofOfWork('s', new MemoryReplayStore(), 10),
			$this->createMock(RemoteCaptcha::class),
		);
	}

	public function testBuiltinIsTheDefault(): void {
		$this->assertSame(CaptchaProvider::BUILTIN, $this->service([])->provider([], null));
	}

	public function testSignedInRespondentsSkipTheCheck(): void {
		$this->assertSame(CaptchaProvider::NONE, $this->service([])->provider([], 'alice'));
		$this->assertTrue($this->service([])->passes([], 'alice', '', '', 1000));
	}

	public function testFormCanTurnItOff(): void {
		$definition = ['settings' => ['captcha' => false]];
		$this->assertSame(CaptchaProvider::NONE, $this->service([])->provider($definition, null));
		$this->assertNull($this->service([])->publicConfig($definition, null));
	}

	public function testAnonymousSubmissionWithoutAnswerFails(): void {
		$this->assertFalse($this->service([])->passes([], null, '', '', 1000));
	}

	public function testNoneProviderAlwaysPasses(): void {
		$service = $this->service(['captcha_provider' => 'none']);
		$this->assertNull($service->publicConfig([], null));
		$this->assertTrue($service->passes([], null, '', '', 1000));
		$this->assertNull($service->challenge(1000));
	}

	public function testRemoteProviderWithoutKeysCountsAsNone(): void {
		$service = $this->service(['captcha_provider' => 'turnstile']);
		$this->assertSame(CaptchaProvider::NONE, $service->provider([], null));
	}

	public function testPublicConfigCarriesSiteKeyForRemoteProviders(): void {
		// The secret is read with the sensitive flag; the stub returns it for the same key.
		$service = $this->service(['captcha_provider' => 'turnstile', 'captcha_site_key' => 'abc', 'captcha_secret' => 'shh']);
		$this->assertSame(['provider' => 'turnstile', 'siteKey' => 'abc'], $service->publicConfig([], null));
	}

	public function testBuiltinChallengeIsIssued(): void {
		$challenge = $this->service([])->challenge(1000);
		$this->assertIsArray($challenge);
		$this->assertSame('SHA-256', $challenge['algorithm']);
	}
}
