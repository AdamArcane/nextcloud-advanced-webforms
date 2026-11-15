<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Security\Captcha;

use OCA\Webforms\Security\Captcha\CaptchaHttp;
use OCA\Webforms\Security\Captcha\CaptchaProvider;
use OCA\Webforms\Security\Captcha\RemoteCaptcha;
use PHPUnit\Framework\TestCase;

class RemoteCaptchaTest extends TestCase {
	private function remote(array|\Throwable $answer, ?array &$sent = null): RemoteCaptcha {
		$http = new class($answer, $sent) implements CaptchaHttp {
			public function __construct(private readonly array|\Throwable $answer, private ?array &$sent) {
			}

			public function postForm(string $url, array $fields, int $timeoutSeconds): array {
				$this->sent = ['url' => $url, 'fields' => $fields];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}
				return $this->answer;
			}
		};
		return new RemoteCaptcha($http);
	}

	public function testTurnstileSuccess(): void {
		$sent = null;
		$this->assertTrue($this->remote(['success' => true], $sent)->verify(CaptchaProvider::TURNSTILE, 'sec', 'site', 'resp', '203.0.113.9'));
		$this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $sent['url']);
		$this->assertSame(['secret' => 'sec', 'response' => 'resp', 'remoteip' => '203.0.113.9'], $sent['fields']);
	}

	public function testHcaptchaSendsSiteKey(): void {
		$sent = null;
		$this->assertTrue($this->remote(['success' => true], $sent)->verify(CaptchaProvider::HCAPTCHA, 'sec', 'site', 'resp', ''));
		$this->assertSame('https://api.hcaptcha.com/siteverify', $sent['url']);
		$this->assertSame(['secret' => 'sec', 'response' => 'resp', 'sitekey' => 'site'], $sent['fields']);
	}

	public function testRefusalIsAFailure(): void {
		$this->assertFalse($this->remote(['success' => false])->verify(CaptchaProvider::TURNSTILE, 's', 'k', 'r', ''));
		$this->assertFalse($this->remote(['success' => 'true'])->verify(CaptchaProvider::TURNSTILE, 's', 'k', 'r', ''));
		$this->assertFalse($this->remote([])->verify(CaptchaProvider::TURNSTILE, 's', 'k', 'r', ''));
	}

	public function testFailsClosedWhenProviderIsDown(): void {
		$this->assertFalse($this->remote(new \RuntimeException('timeout'))->verify(CaptchaProvider::TURNSTILE, 's', 'k', 'r', ''));
	}

	public function testEmptyResponseIsNotSent(): void {
		$sent = null;
		$this->assertFalse($this->remote(['success' => true], $sent)->verify(CaptchaProvider::TURNSTILE, 's', 'k', '', ''));
		$this->assertNull($sent);
	}
}
