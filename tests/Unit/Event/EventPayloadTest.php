<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Event;

use OCA\Webforms\Event\EventPayload;
use PHPUnit\Framework\TestCase;

final class EventPayloadTest extends TestCase {
	public function testEntryCreatedIsTheWebhookBodyPlusFormAndOwner(): void {
		$payload = EventPayload::entryCreated(
			formId: 12,
			formTitle: 'Contact',
			ownerId: 'ada',
			versionId: 3,
			entryId: 457,
			userId: null,
			submittedAt: 1794700000,
			fields: [
				'f_name' => ['type' => 'text', 'label' => 'Name'],
				'f_cv' => ['type' => 'file', 'label' => 'CV'],
				'f_intro' => ['type' => 'paragraph', 'label' => 'Intro'],
			],
			data: [
				'f_name' => 'Ada',
				'f_cv' => [['name' => 'cv.pdf', 'size' => 25, 'mime' => 'application/pdf', 'path' => '/secret/cv.pdf']],
			],
		);

		$this->assertSame('entry.created', $payload['event']);
		$this->assertSame(12, $payload['formId']);
		$this->assertSame('Contact', $payload['formTitle']);
		$this->assertSame('ada', $payload['ownerId']);
		$this->assertSame(457, $payload['entryId']);
		$this->assertNull($payload['userId']);
		$this->assertSame(['label' => 'Name', 'value' => 'Ada'], $payload['data']['f_name']);
		// Where a file is stored is private, and non-input fields are not answers.
		$this->assertSame([['name' => 'cv.pdf', 'size' => 25, 'mime' => 'application/pdf']], $payload['data']['f_cv']['value']);
		$this->assertArrayNotHasKey('f_intro', $payload['data']);
		$this->assertSame('{"label":"CV","value":[{"name":"cv.pdf","size":25,"mime":"application\/pdf"}]}', json_encode($payload['data']['f_cv']));
	}

	public function testEntryCreatedWithNoAnswersKeepsDataAnObject(): void {
		$payload = EventPayload::entryCreated(1, 'T', 'ada', 1, 1, 'bob', 1794700000, [], []);
		$this->assertStringContainsString('"data":{}', json_encode($payload));
	}

	public function testEntryDeletedCarriesNoAnswers(): void {
		$payload = EventPayload::entryDeleted(12, 'Contact', 'ada', 3, 457, 'ada');
		$this->assertSame([
			'event' => 'entry.deleted',
			'formId' => 12,
			'versionId' => 3,
			'entryId' => 457,
			'deletedBy' => 'ada',
			'formTitle' => 'Contact',
			'ownerId' => 'ada',
		], $payload);
	}

	public function testFormPublished(): void {
		$payload = EventPayload::formPublished(12, 'Contact', 'ada', 9, 3, true, 'tok123');
		$this->assertSame('form.published', $payload['event']);
		$this->assertSame(3, $payload['versionNumber']);
		$this->assertTrue($payload['newVersion']);
		$this->assertSame('tok123', $payload['publicToken']);
	}

	public function testFormStatusChangedUsesNames(): void {
		$payload = EventPayload::formStatusChanged(12, 'Contact', 'ada', 2, 1);
		$this->assertSame('form.status_changed', $payload['event']);
		$this->assertSame('closed', $payload['status']);
		$this->assertSame('published', $payload['previousStatus']);
		$this->assertSame('unknown', EventPayload::formStatusChanged(1, 'T', 'a', 9, 0)['status']);
	}
}
