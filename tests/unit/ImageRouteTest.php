<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\PublicVoteController;
use OCA\Pulse\Controller\RoomApiController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Image routes end up in an <img src> — and the browser sends no request
 * token with it. If NoCSRFRequired is missing, Nextcloud answers with 412 and
 * the image stays empty, without anything conspicuous in the log. That is exactly
 * what happened in the moderator (26.07., found with the screenshot test bench).
 */
class ImageRouteTest extends TestCase {
	public static function imageRoutes(): array {
		return [
			'moderator preview' => [RoomApiController::class, 'showImage'],
			'public delivery' => [PublicVoteController::class, 'image'],
		];
	}

	#[DataProvider('imageRoutes')]
	public function testImageRouteAllowsPlainImgRequest(string $class, string $method): void {
		$attributes = (new \ReflectionMethod($class, $method))->getAttributes(NoCSRFRequired::class);
		$this->assertNotEmpty(
			$attributes,
			"$class::$method serves an image and needs #[NoCSRFRequired], "
			. 'otherwise the <img src> fails with 412.',
		);
	}
}
