<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\ApiShape;
use OCA\Webforms\Forms\EntryFilter;
use PHPUnit\Framework\TestCase;

final class ApiShapeTest extends TestCase {
	public function testAFormShowsOnlyWhatSection23Lists(): void {
		$described = [
			'id' => 7, 'title' => 'T', 'description' => null, 'status' => 1, 'role' => 'reviewer', 'ownerId' => 'ada', 'ownerName' => 'Ada',
			'latestVersionId' => 3, 'createdAt' => 10, 'updatedAt' => 20, 'availability' => ['open' => true],
			// None of these may ever be sent.
			'publicToken' => 'secret-token', 'slug' => 'my-form', 'hasPassword' => true, 'embed' => true, 'embedOrigins' => ['https://a.example'],
			'draft' => ['fields' => []], 'retiredIds' => ['f_old'], 'canLeave' => true, 'destinationsPending' => true, 'revision' => 4,
		];
		$shown = ApiShape::form($described, 12);
		$this->assertSame(['id', 'title', 'description', 'status', 'role', 'ownerId', 'ownerName', 'latestVersionId', 'createdAt', 'updatedAt', 'responses', 'availability'], array_keys($shown));
		$this->assertSame('published', $shown['status']);
		$this->assertSame(12, $shown['responses']);
		$this->assertStringNotContainsString('secret-token', (string)json_encode($shown));
	}

	public function testStatusWords(): void {
		$this->assertSame('draft', ApiShape::form(['status' => 0], 0)['status']);
		$this->assertSame('closed', ApiShape::form(['status' => 2], 0)['status']);
		$this->assertSame('draft', ApiShape::form(['status' => 99], 0)['status']);
	}

	public function testAResponseHasNoNoteAndFilesAndSignaturesAreReduced(): void {
		$fields = [
			'f_name' => ['type' => 'text'],
			'f_cv' => ['type' => 'file'],
			'f_sig' => ['type' => 'signature'],
			'f_tags' => ['type' => 'checkboxes'],
			'f_ok' => ['type' => 'checkbox'],
		];
		$data = [
			'f_name' => 'Ada',
			'f_cv' => [['name' => 'cv.pdf', 'size' => 1200, 'mime' => 'application/pdf', 'storage' => 'files', 'ref' => '/Forms/secret/path/cv.pdf']],
			'f_sig' => 'data:image/png;base64,AAAA',
			'f_tags' => ['a', 'b'],
			'f_ok' => false,
		];
		$response = ApiShape::response(5, 2, 'ada', 1700000000, 'done', $data, $fields);
		$this->assertSame(['id', 'versionId', 'submittedAt', 'respondent', 'status', 'answers'], array_keys($response));
		$answers = (array)$response['answers'];
		$this->assertSame('Ada', $answers['f_name']);
		$this->assertSame([['name' => 'cv.pdf', 'size' => 1200, 'mime' => 'application/pdf']], $answers['f_cv']);
		$this->assertTrue($answers['f_sig']);
		$this->assertSame(['a', 'b'], $answers['f_tags']);
		$this->assertFalse($answers['f_ok']);
		$json = (string)json_encode($response);
		$this->assertStringNotContainsString('secret/path', $json);
		$this->assertStringNotContainsString('data:image', $json);
	}

	public function testNoAnswersIsAnObjectNotAList(): void {
		$this->assertSame('{}', json_encode(ApiShape::response(1, 1, null, 1, null, [], [])['answers']));
	}

	public function testAnEmptySignatureIsLeftOut(): void {
		$this->assertSame([], ApiShape::answers(['f_sig' => ''], ['f_sig' => ['type' => 'signature']]));
	}

	public function testTheAfterCursorMakesAFilterActiveAndIgnoresNonsense(): void {
		$this->assertTrue(EntryFilter::fromInput('', '', '', '', '', 'oldest', '', 41)->isActive());
		$this->assertSame(41, EntryFilter::fromInput('', '', '', '', '', 'oldest', '', 41)->afterId);
		$this->assertNull(EntryFilter::fromInput('', '', '', '', '', 'oldest', '', 0)->afterId);
		$this->assertNull(EntryFilter::fromInput('', '', '', '', '', 'oldest', '', -5)->afterId);
		$this->assertFalse(EntryFilter::fromInput('', '', '', '', '', 'oldest')->isActive());
	}
}
