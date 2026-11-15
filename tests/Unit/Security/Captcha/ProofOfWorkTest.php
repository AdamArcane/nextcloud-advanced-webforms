<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Security\Captcha;

use OCA\Webforms\Security\Captcha\ProofOfWork;
use OCA\Webforms\Security\Captcha\ReplayStore;
use PHPUnit\Framework\TestCase;

class ProofOfWorkTest extends TestCase {
	private function pow(?ReplayStore $store = null, int $max = 300): ProofOfWork {
		return new ProofOfWork('instance-secret', $store ?? new MemoryReplayStore(), $max);
	}

	/**
	 * What the browser does: try numbers until the hash matches.
	 *
	 * @param array<string, mixed> $c
	 */
	private function solve(array $c, ?string $signature = null): string {
		for ($n = 0; $n <= $c['maxnumber']; $n++) {
			if (hash('sha256', $c['salt'] . $n) === $c['challenge']) {
				return base64_encode((string)json_encode([
					'algorithm' => 'SHA-256',
					'challenge' => $c['challenge'],
					'number' => $n,
					'salt' => $c['salt'],
					'signature' => $signature ?? $c['signature'],
				]));
			}
		}
		$this->fail('unsolvable');
	}

	public function testSolvedChallengeIsAccepted(): void {
		$pow = $this->pow();
		$this->assertTrue($pow->verify($this->solve($pow->issue(1000)), 1010));
	}

	public function testSolutionWorksOnlyOnce(): void {
		$pow = $this->pow();
		$solution = $this->solve($pow->issue(1000));
		$this->assertTrue($pow->verify($solution, 1010));
		$this->assertFalse($pow->verify($solution, 1011));
	}

	public function testExpiredChallengeIsRejected(): void {
		$pow = $this->pow();
		$solution = $this->solve($pow->issue(1000));
		$this->assertFalse($pow->verify($solution, 1000 + 601));
	}

	public function testChallengeFromAnotherServerIsRejected(): void {
		$other = new ProofOfWork('other-secret', new MemoryReplayStore(), 300);
		$this->assertFalse($this->pow()->verify($this->solve($other->issue(1000)), 1010));
	}

	public function testForgedChallengeIsRejected(): void {
		// The attacker picks an easy challenge and signs it with a guess.
		$salt = 'aa?expires=9999999';
		$challenge = hash('sha256', $salt . '0');
		$payload = base64_encode((string)json_encode([
			'algorithm' => 'SHA-256', 'challenge' => $challenge, 'number' => 0, 'salt' => $salt, 'signature' => str_repeat('0', 64),
		]));
		$this->assertFalse($this->pow()->verify($payload, 1000));
	}

	public function testWrongNumberIsRejected(): void {
		$pow = $this->pow();
		$c = $pow->issue(1000);
		$right = json_decode((string)base64_decode($this->solve($c)), true);
		$right['number'] = $right['number'] === 0 ? 1 : $right['number'] - 1;
		$this->assertFalse($pow->verify(base64_encode((string)json_encode($right)), 1010));
	}

	public function testGarbageIsRejected(): void {
		$pow = $this->pow();
		$this->assertFalse($pow->verify('', 1000));
		$this->assertFalse($pow->verify('not base64 !!', 1000));
		$this->assertFalse($pow->verify(base64_encode('[]'), 1000));
		$this->assertFalse($pow->verify(str_repeat('a', 5000), 1000));
	}
}
