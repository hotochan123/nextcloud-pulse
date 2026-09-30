<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Db\RoomMapper;
use OCA\Pulse\Service\CodeGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Room codes: alphabet without I/O/0/1 (reading aloud/typing), collisions are
 * re-rolled instead of passed through.
 */
#[CoversClass(CodeGenerator::class)]
class CodeGeneratorTest extends TestCase {

    private const SAFE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function testRoomCodeIsSixCharactersFromTheUnambiguousAlphabet(): void {
        $random = $this->createMock(ISecureRandom::class);
        $random->expects($this->once())
            ->method('generate')
            ->with(6, self::SAFE_ALPHABET)
            ->willReturn('K9RY8M');
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('codeExists')->willReturn(false);

        $this->assertSame('K9RY8M', (new CodeGenerator($random, $mapper))->uniqueRoomCode());
    }

    public function testAlphabetContainsNoConfusableCharacters(): void {
        foreach (['I', 'O', '0', '1'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, self::SAFE_ALPHABET);
        }
    }

    public function testTakenCodeIsRerolled(): void {
        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturnOnConsecutiveCalls('AAAAAA', 'BBBBBB');
        $mapper = $this->createMock(RoomMapper::class);
        // First roll collides, second is free.
        $mapper->method('codeExists')->willReturnMap([['AAAAAA', true], ['BBBBBB', false]]);

        $this->assertSame('BBBBBB', (new CodeGenerator($random, $mapper))->uniqueRoomCode());
    }

    public function testAfterTwentyCollisionsItGivesUpInsteadOfSpinningForever(): void {
        $random = $this->createMock(ISecureRandom::class);
        $random->expects($this->exactly(20))->method('generate')->willReturn('AAAAAA');
        $mapper = $this->createMock(RoomMapper::class);
        $mapper->method('codeExists')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        (new CodeGenerator($random, $mapper))->uniqueRoomCode();
    }

    public function testOptionIdIsFourCharactersFromTheSameAlphabet(): void {
        $random = $this->createMock(ISecureRandom::class);
        $random->expects($this->once())->method('generate')->with(4, self::SAFE_ALPHABET)->willReturn('AB12');

        $this->assertSame('AB12', (new CodeGenerator($random, $this->createMock(RoomMapper::class)))->optionId());
    }

    public function testVoterTokenIsThirtyTwoCharacters(): void {
        // 32 characters is a hard limit: all three token columns are varchar(32).
        $random = $this->createMock(ISecureRandom::class);
        $random->expects($this->once())
            ->method('generate')
            ->with(32, ISecureRandom::CHAR_ALPHANUMERIC)
            ->willReturn(str_repeat('a', 32));

        $token = (new CodeGenerator($random, $this->createMock(RoomMapper::class)))->voterToken();
        $this->assertSame(32, strlen($token));
    }

    public function testVoterTokenFormIsRecognised(): void {
        // Only what voterToken() hands out counts as a cookie: anything else would fail
        // on the varchar(32) column or on the database's UTF-8.
        $this->assertTrue(CodeGenerator::isVoterToken(str_repeat('a', 32)));
        $this->assertTrue(CodeGenerator::isVoterToken(str_pad('AZaz09', 32, 'x')));

        foreach ([
            'zu kurz' => str_repeat('a', 31),
            'zu lang' => str_repeat('a', 33),
            'fremde Form' => 'tok-anna',
            'Zeilenumbruch am Ende' => str_repeat('a', 32) . "\n",
            'kaputtes UTF-8' => str_repeat("\xFF", 32),
            'Umlaute' => str_repeat('ä', 32),
            'leer' => '',
        ] as $case => $value) {
            $this->assertFalse(CodeGenerator::isVoterToken($value), $case);
        }

        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturn(str_repeat('a', 32));
        $generator = new CodeGenerator($random, $this->createMock(RoomMapper::class));
        $this->assertTrue(CodeGenerator::isVoterToken($generator->voterToken()));
    }
}
