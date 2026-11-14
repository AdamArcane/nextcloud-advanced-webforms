<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Uploads;

use OCA\AdvancedForms\Uploads\PendingUpload;
use OCA\AdvancedForms\Uploads\Ports\MimePort;
use OCA\AdvancedForms\Uploads\Ports\OwnerFilesPort;
use OCA\AdvancedForms\Uploads\Ports\PendingStorePort;
use OCA\AdvancedForms\Uploads\Ports\TokenPort;
use OCA\AdvancedForms\Uploads\Ports\UploadRegistryPort;
use OCA\AdvancedForms\Uploads\UploadRejected;
use OCA\AdvancedForms\Uploads\UploadService;
use PHPUnit\Framework\TestCase;

/**
 * In-memory stand-ins for the Nextcloud side, so the rules can be tested without a server.
 */
class UploadServiceTest extends TestCase {
	/** @var array<string, PendingUpload> */
	public array $pending = [];
	/** @var array<string, string> token => contents */
	public array $contents = [];
	/** @var array<int, array{owner: string, folder: string, subfolder: string, name: string, body: string}> */
	public array $stored = [];
	public int $nextToken = 0;
	public string $mime = 'image/png';
	public bool $canWrite = true;
	public bool $canShare = true;
	public bool $failStoreAt = false;
	/** @var list<int> */
	public array $deleted = [];
	private ?string $tmp = null;

	protected function tearDown(): void {
		if ($this->tmp !== null && is_file($this->tmp)) {
			unlink($this->tmp);
		}
	}

	private function tmpFile(string $body = 'data'): string {
		$this->tmp = tempnam(sys_get_temp_dir(), 'adv');
		file_put_contents($this->tmp, $body);
		return $this->tmp;
	}

	private function service(): UploadService {
		$test = $this;
		$registry = new class($test) implements UploadRegistryPort {
			public function __construct(private UploadServiceTest $t) {
			}

			public function add(PendingUpload $upload): void {
				$this->t->pending[$upload->token] = $upload;
			}

			public function find(string $token): ?PendingUpload {
				return $this->t->pending[$token] ?? null;
			}

			public function remove(string $token): void {
				unset($this->t->pending[$token]);
			}

			public function pendingBytes(int $formId): int {
				return array_sum(array_map(static fn (PendingUpload $u): int => $u->formId === $formId ? $u->size : 0, $this->t->pending));
			}

			public function forForm(int $formId): array {
				return array_values(array_filter($this->t->pending, static fn (PendingUpload $u): bool => $u->formId === $formId));
			}

			public function expired(int $olderThan, int $limit): array {
				return array_values(array_filter($this->t->pending, static fn (PendingUpload $u): bool => $u->createdAt < $olderThan));
			}
		};
		$store = new class($test) implements PendingStorePort {
			public function __construct(private UploadServiceTest $t) {
			}

			public function put(string $token, string $localPath): void {
				$this->t->contents[$token] = (string)file_get_contents($localPath);
			}

			public function read(string $token) {
				if (!isset($this->t->contents[$token])) {
					return null;
				}
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $this->t->contents[$token]);
				rewind($stream);
				return $stream;
			}

			public function delete(string $token): void {
				unset($this->t->contents[$token]);
			}
		};
		$files = new class($test) implements OwnerFilesPort {
			public function __construct(private UploadServiceTest $t) {
			}

			public function canWrite(string $ownerId, string $folder): bool {
				return $this->t->canWrite;
			}

			public function canShareByLink(string $ownerId, string $folder): bool {
				return $this->t->canShare;
			}

			public function store(string $ownerId, string $folder, string $subfolder, string $name, $stream): int {
				if ($this->t->failStoreAt && count($this->t->stored) === 1) {
					throw new \RuntimeException('quota');
				}
				$id = 100 + count($this->t->stored);
				$this->t->stored[$id] = ['owner' => $ownerId, 'folder' => $folder, 'subfolder' => $subfolder, 'name' => $name, 'body' => (string)stream_get_contents($stream)];
				return $id;
			}

			public function open(string $ownerId, int $fileId): ?array {
				return null;
			}

			public function delete(string $ownerId, int $fileId): void {
				$this->t->deleted[] = $fileId;
				unset($this->t->stored[$fileId]);
			}
		};
		$tokens = new class($test) implements TokenPort {
			public function __construct(private UploadServiceTest $t) {
			}

			public function newToken(): string {
				return str_pad('t' . ++$this->t->nextToken, 32, 'a');
			}
		};
		$mimes = new class($test) implements MimePort {
			public function __construct(private UploadServiceTest $t) {
			}

			public function detect(string $localPath, string $name): string {
				return $this->t->mime;
			}
		};
		return new UploadService($registry, $store, $files, $tokens, $mimes);
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function field(array $extra = []): array {
		return array_merge(['type' => 'file', 'label' => 'Upload'], $extra);
	}

	private function reason(callable $action): string {
		try {
			$action();
		} catch (UploadRejected $e) {
			return $e->reason;
		}
		$this->fail('Expected the upload to be rejected');
	}

	public function testReceiveStoresPendingUpload(): void {
		$result = $this->service()->receive(1, 'f_cv', $this->field(), null, '../../Résumé.PDF', $this->tmpFile(), 4, 1000);
		$this->assertSame(['id' => str_pad('t1', 32, 'a'), 'name' => 'Résumé.PDF', 'size' => 4], $result);
		$this->assertSame('data', $this->contents[$result['id']]);
		$this->assertSame('f_cv', $this->pending[$result['id']]->fieldId);
	}

	public function testReceiveRefusals(): void {
		$s = $this->service();
		$path = $this->tmpFile();
		$this->assertSame('upload_type_not_allowed', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(), null, 'run.exe', $path, 4, 1)));
		$this->assertSame('upload_type_not_allowed', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(['accept' => ['.php']]), null, 'a.php', $path, 4, 1)));
		$this->assertSame('upload_type_not_allowed', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(), null, 'shell.php.jpg', $path, 4, 1)));
		$this->assertSame('upload_type_not_allowed', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(), null, 'notes.zip', $path, 4, 1)));
		$this->assertSame('upload_bad_name', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(), null, '...', $path, 4, 1)));
		$this->assertSame('upload_empty', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $path, 0, 1)));
		$this->assertSame('upload_too_large', $this->reason(fn () => $s->receive(1, 'f_cv', $this->field(['maxSizeMb' => 1]), null, 'a.png', $path, 1024 * 1024 + 1, 1)));
		$this->assertSame('upload_not_a_file_field', $this->reason(fn () => $s->receive(1, 'f_t', ['type' => 'text'], null, 'a.png', $path, 4, 1)));
		$this->assertSame([], $this->pending);
	}

	public function testReceiveRefusesDisguisedContent(): void {
		$this->mime = 'application/x-dosexec';
		$this->assertSame('upload_type_not_allowed', $this->reason(fn () => $this->service()->receive(1, 'f_cv', $this->field(), null, 'photo.png', $this->tmpFile(), 4, 1)));
	}

	public function testLoginOnlyFieldNeedsAnAccount(): void {
		$s = $this->service();
		$field = $this->field(['audience' => 'login']);
		$this->assertSame('upload_login_required', $this->reason(fn () => $s->receive(1, 'f_cv', $field, null, 'a.png', $this->tmpFile(), 4, 1)));
		$this->assertArrayHasKey('id', $s->receive(1, 'f_cv', $field, 'alice', 'a.png', $this->tmpFile(), 4, 1));
	}

	public function testPendingBytesAreCappedPerForm(): void {
		$s = $this->service();
		$field = $this->field(['maxSizeMb' => 100]);
		$mb100 = 100 * 1024 * 1024;
		$s->receive(1, 'f_cv', $field, null, 'a.png', $this->tmpFile(), $mb100, 1);
		$s->receive(1, 'f_cv', $field, null, 'b.png', $this->tmpFile(), $mb100, 1);
		$this->assertSame('upload_busy', $this->reason(fn () => $s->receive(1, 'f_cv', $field, null, 'c.png', $this->tmpFile(), 10, 1)));
		// Another form has its own allowance.
		$this->assertArrayHasKey('id', $s->receive(2, 'f_cv', $field, null, 'c.png', $this->tmpFile(), 10, 1));
	}

	public function testClaimStoresInOwnersFilesAsTheOwner(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile('AAAA'), 4, 1000);
		$data = $s->claim(1, 'owner', 'My form', ['f_cv' => $this->field(['maxFiles' => 3])], ['f_cv' => [$a['id']], 'f_name' => 'x'], null, 2000);

		$this->assertSame('x', $data['f_name']);
		$this->assertSame([['name' => 'a.png', 'size' => 4, 'mime' => 'image/png', 'storage' => 'files', 'ref' => '100']], $data['f_cv']);
		$this->assertSame('owner', $this->stored[100]['owner']);
		$this->assertSame('Advanced Forms/My form', $this->stored[100]['folder']);
		$this->assertStringStartsWith('Response 1970-01-01 00.33.20', $this->stored[100]['subfolder']);
		$this->assertSame('AAAA', $this->stored[100]['body']);
		$this->assertSame([], $this->pending, 'the pending upload is consumed');
		$this->assertSame([], $this->contents);
	}

	public function testClaimUsesTheFieldsFolder(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$s->claim(1, 'owner', 'T', ['f_cv' => $this->field(['folder' => 'Work/Applications'])], ['f_cv' => [$a['id']]], null, 2);
		$this->assertSame('Work/Applications', $this->stored[100]['folder']);
	}

	public function testAppStorageKeepsTheContentAndUsesTheTokenAsReference(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(['storage' => 'app']), null, 'a.png', $this->tmpFile(), 4, 1);
		$this->canWrite = false; // irrelevant for app storage
		$data = $s->claim(1, 'owner', 'T', ['f_cv' => $this->field(['storage' => 'app'])], ['f_cv' => [$a['id']]], null, 2);
		$this->assertSame('app', $data['f_cv'][0]['storage']);
		$this->assertSame($a['id'], $data['f_cv'][0]['ref']);
		$this->assertSame([], $this->pending);
		$this->assertArrayHasKey($a['id'], $this->contents, 'the file stays in the app storage');
	}

	public function testClaimRefusesWhereTheOwnerCannotWrite(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$this->canWrite = false;
		$this->assertSame('upload_destination_unavailable', $this->reason(fn () => $s->claim(1, 'owner', 'T', ['f_cv' => $this->field()], ['f_cv' => [$a['id']]], null, 2)));
		$this->assertSame([], $this->stored);
		$this->assertArrayHasKey($a['id'], $this->pending, 'the respondent can try again');
	}

	public function testAnonymousUploadsIntoFilesNeedPublicLinkSharing(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$this->canShare = false;
		$this->assertSame('upload_anonymous_not_allowed', $this->reason(fn () => $s->claim(1, 'owner', 'T', ['f_cv' => $this->field()], ['f_cv' => [$a['id']]], null, 2)));
		// Signed-in only fields do not need that.
		$b = $s->receive(1, 'f_cv', $this->field(['audience' => 'login']), 'alice', 'b.png', $this->tmpFile(), 4, 1);
		$data = $s->claim(1, 'owner', 'T', ['f_cv' => $this->field(['audience' => 'login'])], ['f_cv' => [$b['id']]], 'alice', 2);
		$this->assertSame('files', $data['f_cv'][0]['storage']);
	}

	public function testClaimRejectsUnknownOrForeignUploads(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$fields = ['f_cv' => $this->field(), 'f_other' => $this->field()];
		$this->assertSame('upload_missing', $this->reason(fn () => $s->claim(1, 'o', 'T', $fields, ['f_cv' => [str_repeat('z', 32)]], null, 2)));
		$this->assertSame('upload_missing', $this->reason(fn () => $s->claim(2, 'o', 'T', $fields, ['f_cv' => [$a['id']]], null, 2)), 'another form');
		$this->assertSame('upload_missing', $this->reason(fn () => $s->claim(1, 'o', 'T', $fields, ['f_other' => [$a['id']]], null, 2)), 'another field');
	}

	public function testAUploadCanOnlyBeUsedOnce(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$fields = ['f_cv' => $this->field()];
		$s->claim(1, 'o', 'T', $fields, ['f_cv' => [$a['id']]], null, 2);
		$this->assertSame('upload_missing', $this->reason(fn () => $s->claim(1, 'o', 'T', $fields, ['f_cv' => [$a['id']]], null, 3)));
	}

	public function testAFailureHalfwayRemovesWhatWasStored(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$b = $s->receive(1, 'f_cv', $this->field(), null, 'b.png', $this->tmpFile(), 4, 1);
		$this->failStoreAt = true;
		$this->assertSame('upload_store_failed', $this->reason(fn () => $s->claim(1, 'o', 'T', ['f_cv' => $this->field(['maxFiles' => 2])], ['f_cv' => [$a['id'], $b['id']]], null, 2)));
		$this->assertSame([], $this->stored);
		$this->assertSame([100], $this->deleted);
		$this->assertCount(2, $this->pending, 'both uploads stay for another try');
	}

	public function testCleanupRemovesOldUploadsOnly(): void {
		$s = $this->service();
		$old = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1000);
		$new = $s->receive(1, 'f_cv', $this->field(), null, 'b.png', $this->tmpFile(), 4, 1000 + 20 * 3600);
		$this->assertSame(1, $s->cleanup(1000 + UploadService::PENDING_TTL_SECONDS + 10));
		$this->assertArrayNotHasKey($old['id'], $this->pending);
		$this->assertArrayNotHasKey($old['id'], $this->contents);
		$this->assertArrayHasKey($new['id'], $this->pending);
	}

	public function testDeleteFilesOfRemovesBothKinds(): void {
		$s = $this->service();
		$this->contents['tok'] = 'x';
		$fields = ['f_cv' => $this->field()];
		$s->deleteFilesOf('owner', $fields, ['f_cv' => [
			['name' => 'a', 'size' => 1, 'mime' => 'x', 'storage' => 'files', 'ref' => '42'],
			['name' => 'b', 'size' => 1, 'mime' => 'x', 'storage' => 'app', 'ref' => 'tok'],
		], 'f_name' => 'x']);
		$this->assertSame([42], $this->deleted);
		$this->assertArrayNotHasKey('tok', $this->contents);
	}

	public function testDiscardPendingForAForm(): void {
		$s = $this->service();
		$a = $s->receive(1, 'f_cv', $this->field(), null, 'a.png', $this->tmpFile(), 4, 1);
		$b = $s->receive(2, 'f_cv', $this->field(), null, 'b.png', $this->tmpFile(), 4, 1);
		$s->discardPending(1);
		$this->assertArrayNotHasKey($a['id'], $this->pending);
		$this->assertArrayNotHasKey($a['id'], $this->contents);
		$this->assertArrayHasKey($b['id'], $this->pending);
	}

	public function testOpenReadsFromTheRightPlace(): void {
		$s = $this->service();
		$this->contents['tok'] = 'hello';
		$file = $s->open('owner', ['name' => 'a.txt', 'size' => 5, 'mime' => 'text/plain', 'storage' => 'app', 'ref' => 'tok']);
		$this->assertSame('hello', stream_get_contents($file['stream']));
		$this->assertNull($s->open('owner', ['name' => 'a', 'size' => 1, 'mime' => 'x', 'storage' => 'app', 'ref' => 'nope']));
		$this->assertNull($s->open('owner', ['name' => 'a', 'size' => 1, 'mime' => 'x', 'storage' => 'files', 'ref' => 'abc']));
	}
}
