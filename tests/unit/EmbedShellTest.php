<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The PowerPoint embed shell (js/pulse-embed.js, hand-written, no bundle)
 * frames only a real room code (security review I2). The saved code comes
 * from the document settings, which travel with a .pptx, from ?code= or
 * from localStorage; '..' used to reach the iframe unchecked, and
 * /apps/pulse/screen/.. resolves to /apps/pulse/ — the moderator page or the
 * login page inside the slide.
 *
 * The container has no JavaScript runtime, so this is a guard on the source:
 * every path to renderIframe() passes the room-code pattern first. A run of
 * start() against a fake DOM belongs to the checks of the change.
 */
#[CoversNothing]
class EmbedShellTest extends TestCase {

    private const CODE = '/^[A-Z0-9]{6}$/';

    private string $js;

    protected function setUp(): void {
        $this->js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pulse-embed.js');
    }

    public function testStartFramesOnlyAnUpperCasedRoomCode(): void {
        $start = $this->body('start');

        $this->assertStringContainsString("String(saved || '').toUpperCase()", $start);
        $this->assertMatchesRegularExpression(
            '~if \(' . preg_quote(self::CODE, '~') . '\.test\(code\)\) \{ renderIframe\(code\); \}~',
            $start,
        );
    }

    public function testAnythingElseOpensTheFormWithTheSavedText(): void {
        $this->assertMatchesRegularExpression(
            "~else \{ renderForm\(typeof saved === 'string' \? saved : ''\); \}~",
            $this->body('start'),
        );
    }

    public function testTheFormChecksTheSameBeforeItFrames(): void {
        $go = $this->body('go');
        $check = strpos($go, '!' . self::CODE . '.test(code)');
        $frame = strpos($go, 'renderIframe(code)');

        $this->assertNotFalse($check);
        $this->assertNotFalse($frame);
        $this->assertLessThan($frame, $check);
    }

    public function testNoOtherPathFrames(): void {
        // The definition plus the two checked calls in start() and go().
        $this->assertSame(3, substr_count($this->js, 'renderIframe('));
    }

    /** Source of `function $name() { … }` up to its matching brace. */
    private function body(string $name): string {
        $at = strpos($this->js, 'function ' . $name . '(');
        $this->assertNotFalse($at, "function $name() not found");
        $open = strpos($this->js, '{', $at);
        $depth = 0;
        for ($i = $open, $n = strlen($this->js); $i < $n; $i++) {
            if ($this->js[$i] === '{') {
                $depth++;
            } elseif ($this->js[$i] === '}' && --$depth === 0) {
                return substr($this->js, $open, $i - $open + 1);
            }
        }
        $this->fail("function $name() has no end");
    }
}
