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
 * Bild-Routen landen in einem <img src> — dabei schickt der Browser keinen
 * Requesttoken. Fehlt NoCSRFRequired, antwortet Nextcloud mit 412 und das Bild
 * bleibt leer, ohne dass im Log etwas Auffälliges steht. Genau so ist es im
 * Moderator passiert (26.07., gefunden im Screenshot-Prüfstand).
 */
class ImageRouteTest extends TestCase {
	public static function imageRoutes(): array {
		return [
			'Moderator-Vorschau' => [RoomApiController::class, 'showImage'],
			'öffentliche Auslieferung' => [PublicVoteController::class, 'image'],
		];
	}

	#[DataProvider('imageRoutes')]
	public function testImageRouteAllowsPlainImgRequest(string $class, string $method): void {
		$attributes = (new \ReflectionMethod($class, $method))->getAttributes(NoCSRFRequired::class);
		$this->assertNotEmpty(
			$attributes,
			"$class::$method liefert ein Bild aus und braucht #[NoCSRFRequired], "
			. 'sonst scheitert das <img src> mit 412.',
		);
	}
}
