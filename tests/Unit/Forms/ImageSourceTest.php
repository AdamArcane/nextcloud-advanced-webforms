<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\ImageSource;
use PHPUnit\Framework\TestCase;

final class ImageSourceTest extends TestCase {
	public function testRemoteAddressesMustBeHttps(): void {
		$this->assertSame(ImageSource::REMOTE, ImageSource::kind('https://example.com/logo.png'));
		$this->assertSame(ImageSource::REMOTE, ImageSource::kind('https://cdn.example.com/a/b?size=2'));
		$this->assertNull(ImageSource::kind('http://example.com/logo.png'));
		$this->assertNull(ImageSource::kind('javascript:alert(1)'));
		$this->assertNull(ImageSource::kind('data:image/png;base64,AAAA'));
		$this->assertNull(ImageSource::kind('https://user:secret@example.com/logo.png'));
		$this->assertNull(ImageSource::kind('https://example.com/a b.png'));
		$this->assertNull(ImageSource::kind('https://localhost/logo.png'));
		$this->assertNull(ImageSource::kind('https://example.com/"onerror="x'));
	}

	public function testFilesPathsStayInTheHomeFolderAndArePictures(): void {
		$this->assertSame(ImageSource::FILES, ImageSource::kind('Pictures/logo.png'));
		$this->assertSame('Pictures/logo.PNG', ImageSource::filesPath('/Pictures/logo.PNG'));
		$this->assertSame('image/jpeg', ImageSource::mime('a/photo.JPG'));
		$this->assertNull(ImageSource::kind('../other/logo.png'));
		$this->assertNull(ImageSource::kind('Pictures/../../logo.png'));
		$this->assertNull(ImageSource::kind('Pictures//logo.png'));
		$this->assertNull(ImageSource::kind('Pictures/logo.svg'));
		$this->assertNull(ImageSource::kind('Documents/secret.pdf'));
		$this->assertNull(ImageSource::kind('Pictures'));
		$this->assertNull(ImageSource::kind(''));
		$this->assertNull(ImageSource::kind(null));
		$this->assertNull(ImageSource::kind(str_repeat('a', 1000) . '.png'));
		$this->assertNull(ImageSource::kind(ImageSource::FILES_MARKER));
	}

	public function testRemoteHostsOfADefinition(): void {
		$hosts = ImageSource::remoteHosts(['fields' => [
			'f_a' => ['type' => 'image', 'src' => 'https://CDN.example.com/a.png'],
			'f_b' => ['type' => 'image', 'src' => 'https://cdn.example.com/b.png'],
			'f_c' => ['type' => 'image', 'src' => 'Pictures/logo.png'],
			'f_d' => ['type' => 'paragraph', 'text' => 'https://other.example.com/x.png'],
		]]);
		$this->assertSame(['cdn.example.com'], $hosts);
	}
}
