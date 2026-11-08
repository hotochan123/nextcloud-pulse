<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Tests\Unit;

use OCA\Pulse\Controller\AdminController;
use OCA\Pulse\Controller\AiDraftController;
use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCA\Pulse\Db\Room;
use OCA\Pulse\Service\AiDraftException;
use OCA\Pulse\Service\AiDraftService;
use OCA\Pulse\Service\AiPolicy;
use OCA\Pulse\Service\Limits;
use OCA\Pulse\Service\ModerationService;
use OCA\Pulse\Service\PaceService;
use OCA\Pulse\Service\RoomService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The admin's rules for AI in Pulse: the switch "Allow AI in Pulse", the
 * daily limit per account and the notice confirmed once, as the quiz draft,
 * the switch "AI help" and the projector filter see them (the summary:
 * AiSummaryServiceTest).
 */
#[CoversClass(AiPolicy::class)]
#[CoversClass(AdminController::class)]
class AiPolicyTest extends TestCase {
    use AiPolicyStub;

    private IManager&MockObject $tasks;
    /** @var list<Task> */
    private array $scheduled = [];
    private Room $room;
    private IL10N&MockObject $l10n;

    protected function setUp(): void {
        $this->room = new Room();
        $this->room->setId(5);
        $this->room->setCode('ABCDEF');
        $this->room->setOwnerUid('alice');
        $this->room->setMode('quiz');
        $this->room->setActivePollId(0);

        $this->tasks = $this->createMock(IManager::class);
        $this->tasks->method('getAvailableTaskTypeIds')->willReturn(['core:text2text']);
        $this->tasks->method('scheduleTask')->willReturnCallback(function (Task $t): void {
            $t->setId(41 + count($this->scheduled));
            $this->scheduled[] = $t;
        });
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(fn (string $s, array $p = []): string => vsprintf($s, $p));
    }

    // ── Settings (AK3) ──────────────────────────────────────────────────────

    public function testDefaultsAreOnAnd30(): void {
        $policy = $this->realPolicy();
        $this->assertTrue($policy->enabled());
        $this->assertSame(30, $policy->dailyLimit());
    }

    public function testStoredValuesAreReadAndClamped(): void {
        $policy = $this->realPolicy();
        $this->appValues = ['ai_enabled' => '0', 'ai_daily_limit' => '5000'];
        $this->assertFalse($policy->enabled());
        $this->assertSame(1000, $policy->dailyLimit());
        $this->appValues['ai_daily_limit'] = '-3';
        $this->assertSame(0, $policy->dailyLimit());
        $this->appValues['ai_daily_limit'] = 'lots';
        $this->assertSame(30, $policy->dailyLimit());
    }

    public function testSaveStoresBoth(): void {
        $policy = $this->realPolicy();
        $policy->setSettings(false, '0');
        $this->assertSame(['ai_enabled' => '0', 'ai_daily_limit' => '0'], $this->appValues);
        $policy->setSettings(true, 1000);
        $this->assertSame(['ai_enabled' => '1', 'ai_daily_limit' => '1000'], $this->appValues);
    }

    public static function badLimits(): array {
        return [[-1], [1001], ['abc'], [''], ['5.5'], [2.0], [true], [null], [[3]]];
    }

    #[DataProvider('badLimits')]
    public function testSaveRefusesALimitOutside0To1000(mixed $limit): void {
        $policy = $this->realPolicy();
        $this->appValues = ['ai_enabled' => '1', 'ai_daily_limit' => '7'];
        try {
            $policy->setSettings(false, $limit);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('0 to 1000', $e->getMessage());
        }
        $this->assertSame(['ai_enabled' => '1', 'ai_daily_limit' => '7'], $this->appValues, 'nothing stored');
    }

    public function testAdminRouteIsForAdminsOnly(): void {
        $method = new \ReflectionMethod(AdminController::class, 'saveAi');
        $this->assertSame([], $method->getAttributes(NoAdminRequired::class), 'no NoAdminRequired: Nextcloud answers non-admins with 403');
    }

    public function testAdminRouteSavesAndRefuses(): void {
        $policy = $this->realPolicy();
        $res = $this->admin($policy, ['enabled' => false, 'dailyLimit' => '12'])->saveAi();
        $this->assertSame(200, $res->getStatus());
        $this->assertSame(['enabled' => false, 'dailyLimit' => 12], $res->getData());

        $res = $this->admin($policy, ['enabled' => true, 'dailyLimit' => ''])->saveAi();
        $this->assertSame(400, $res->getStatus());
        $res = $this->admin($policy, ['enabled' => 'maybe', 'dailyLimit' => '3'])->saveAi();
        $this->assertSame(400, $res->getStatus());
        $this->assertSame(['ai_enabled' => '0', 'ai_daily_limit' => '12'], $this->appValues);
    }

    // ── Admin off (AK1) ─────────────────────────────────────────────────────

    public function testAdminOffMeansNoAiAnywhere(): void {
        $policy = $this->realPolicy();
        $this->userValues['alice/ai_notice'] = '1';
        $this->appValues['ai_enabled'] = '0';
        $prefs = [ModerationService::PREF . '5' => '1'];
        $moderation = $this->moderation($policy, $prefs);

        $this->assertFalse($this->draft($policy)->available('alice'));
        $this->assertFalse($moderation->aiAvailable('alice'));
        $this->assertFalse($moderation->aiHelp($this->room), 'the room switch is kept but counts as off');
        $this->assertSame('1', $prefs[ModerationService::PREF . '5']);
        $this->draftFails($policy, 503, 'AI is not available.');

        $poll = new Poll();
        $poll->setId(7);
        $poll->setRoomId(5);
        $poll->setType('words');
        $this->room->setMode('poll');
        $moderation->afterVote($this->room, $poll, ['hello']);
        $this->assertSame([], $this->scheduled, 'no filter check');

        // Admin on again: the room's switch is back as it was.
        $this->appValues['ai_enabled'] = '1';
        $this->assertTrue($moderation->aiHelp($this->room));
        $this->assertTrue($this->draft($policy)->available('alice'));
    }

    // ── Daily limit (AK2) ───────────────────────────────────────────────────

    public function testDailyLimitStopsTheThirdDraftAndResetsNextDay(): void {
        $policy = $this->realPolicy();
        $this->userValues['alice/ai_notice'] = '1';
        $this->appValues['ai_daily_limit'] = '2';

        $this->draft($policy)->start($this->room, 'alice', 'Volcanoes', null, null);
        $this->draft($policy)->start($this->room, 'alice', 'Volcanoes', null, null);
        $this->assertSame('2026-11-05:2', $this->userValues['alice/ai_day']);
        $this->draftFails($policy, 429, 'You have used all 2 AI requests for today. The limit resets tomorrow.', 2);

        // another account has its own count
        $this->userValues['bob/ai_notice'] = '1';
        $this->assertSame(0, $policy->usedToday('bob'));

        $this->policyNow += 86400;
        $this->draft($policy)->start($this->room, 'alice', 'Volcanoes', null, null);
        $this->assertSame('2026-11-06:1', $this->userValues['alice/ai_day']);
    }

    public function testLimitZeroBlocksAtOnce(): void {
        $policy = $this->realPolicy();
        $this->userValues['alice/ai_notice'] = '1';
        $this->appValues['ai_daily_limit'] = '0';
        $this->draftFails($policy, 429, 'You have used all 0 AI requests for today.');
    }

    public function testAFailedScheduleIsNotCounted(): void {
        $policy = $this->realPolicy();
        $this->userValues['alice/ai_notice'] = '1';
        $tasks = $this->createMock(IManager::class);
        $tasks->method('getAvailableTaskTypeIds')->willReturn(['core:text2text']);
        $tasks->method('scheduleTask')->willThrowException(new \RuntimeException('provider down'));
        $this->tasks = $tasks;
        $this->draftFails($policy, 503, 'AI is not available.');
        $this->assertArrayNotHasKey('alice/ai_day', $this->userValues);
    }

    public static function garbageDays(): array {
        return [['x'], [''], ['2026-11-05'], ['2026-11-05:'], ['2026-11-05:-4'], ['2026-11-04:99'], ['2026-11-05:9:9']];
    }

    #[DataProvider('garbageDays')]
    public function testGarbageInTheDayCounterCountsAsZero(string $raw): void {
        $policy = $this->realPolicy();
        $this->userValues['alice/ai_day'] = $raw;
        $this->assertSame(0, $policy->usedToday('alice'));
        $policy->countDaily('alice');
        $this->assertSame('2026-11-05:1', $this->userValues['alice/ai_day']);
    }

    public function testFilterChecksAreNotCounted(): void {
        $policy = $this->realPolicy();
        $this->appValues['ai_daily_limit'] = '0';
        $prefs = [ModerationService::PREF . '5' => '1'];
        $polls = [];
        $moderation = $this->moderation($policy, $prefs, $polls);
        $poll = new Poll();
        $poll->setId(7);
        $poll->setRoomId(5);
        $poll->setType('words');
        $poll->setModeration([]);
        $polls[7] = $poll;
        $this->room->setMode('poll');

        $moderation->afterVote($this->room, $poll, ['hello']);
        $this->assertCount(1, $this->scheduled, 'the check runs even at limit 0');
        $this->assertArrayNotHasKey('alice/ai_day', $this->userValues);
    }

    // ── The notice (AK4) ────────────────────────────────────────────────────

    public function testWithoutTheNoticeNothingStarts(): void {
        $policy = $this->realPolicy();
        try {
            $this->draft($policy)->start($this->room, 'alice', 'Volcanoes', null, null);
            $this->fail('expected 409');
        } catch (AiDraftException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame(['notice' => true], $e->data());
        }
        $prefs = [];
        try {
            $this->moderation($policy, $prefs)->setAiHelp($this->room, true);
            $this->fail('expected 409');
        } catch (AiDraftException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame(['notice' => true], $e->data());
        }
        $this->assertSame([], $prefs, 'the switch stayed off');
        $this->assertSame([], $this->scheduled);
        $this->assertArrayNotHasKey('alice/ai_day', $this->userValues);

        // switching off needs no notice
        $this->moderation($policy, $prefs)->setAiHelp($this->room, false);

        $policy->confirmNotice('alice');
        $this->assertSame('1', $this->userValues['alice/ai_notice']);
        $this->moderation($policy, $prefs)->setAiHelp($this->room, true);
        $this->assertSame('1', $prefs[ModerationService::PREF . '5']);
        $this->assertSame(['task' => 41], $this->draft($policy)->start($this->room, 'alice', 'Volcanoes', null, null));
    }

    public function testNoticeRoutesConfirmAndReport(): void {
        $policy = $this->realPolicy();
        $controller = $this->draftController($policy);
        $this->assertSame(['available' => true, 'notice' => false], $controller->available()->getData());
        $this->assertSame(['notice' => true], $controller->notice()->getData());
        $this->assertSame(['available' => true, 'notice' => true], $controller->available()->getData());
        $this->assertSame([], (new \ReflectionMethod(AiDraftController::class, 'notice'))->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class), 'CSRF on');
    }

    public function testDraftRouteAnswersNoticeFlag(): void {
        $policy = $this->realPolicy();
        $rooms = $this->createMock(RoomService::class);
        $rooms->method('getOwnedRoom')->willReturn($this->room);
        $res = $this->draftController($policy, ['topic' => 'Volcanoes'], $rooms)->start('ABCDEF');
        $this->assertSame(409, $res->getStatus());
        $this->assertTrue($res->getData()['notice']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function draft(AiPolicy $policy): AiDraftService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnArgument(2);
        return new AiDraftService($this->tasks, $this->createMock(IRootFolder::class), $this->l10n, new Limits($appConfig),
            $this->createMock(LoggerInterface::class), $policy);
    }

    private function draftFails(AiPolicy $policy, int $status, string $message, int $scheduledBefore = 0): void {
        try {
            $this->draft($policy)->start($this->room, 'alice', 'Volcanoes', null, null);
            $this->fail('expected ' . $status);
        } catch (AiDraftException $e) {
            $this->assertSame($status, $e->status(), $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertCount($scheduledBefore, $this->scheduled, 'nothing (more) was sent to the AI');
    }

    /**
     * @param array<string, string> $prefs owner preferences (the room switch)
     * @param array<int, Poll> $polls
     */
    private function moderation(AiPolicy $policy, array &$prefs, array &$polls = []): ModerationService {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(function (string $u, string $a, string $key, mixed $d = '') use (&$prefs): mixed {
            return $prefs[$key] ?? $d;
        });
        $config->method('setUserValue')->willReturnCallback(function (string $u, string $a, string $key, mixed $v) use (&$prefs): void {
            $prefs[$key] = (string)$v;
        });
        $config->method('deleteUserValue')->willReturnCallback(function (string $u, string $a, string $key) use (&$prefs): void {
            unset($prefs[$key]);
        });
        $mapper = $this->createMock(PollMapper::class);
        $mapper->method('find')->willReturnCallback(function (int $id) use (&$polls): Poll {
            return clone ($polls[$id] ?? throw new \OCP\AppFramework\Db\DoesNotExistException('no'));
        });
        $mapper->method('update')->willReturnCallback(function (Poll $p) use (&$polls): Poll {
            $polls[$p->getId()] = clone $p;
            return $p;
        });
        $pace = $this->createMock(PaceService::class);
        $pace->method('locked')->willReturnCallback(fn (Room $r, callable $fn): mixed => $fn($r));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->policyNow);
        return new ModerationService($mapper, $pace, $config, $time, $this->tasks, $this->l10n,
            $this->createMock(LoggerInterface::class), $policy);
    }

    private function admin(AiPolicy $policy, array $params): AdminController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $k, mixed $d = null): mixed => $params[$k] ?? $d);
        return new AdminController($request, $policy, $this->l10n);
    }

    private function draftController(AiPolicy $policy, array $params = [], ?RoomService $rooms = null): AiDraftController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $k, mixed $d = null): mixed => $params[$k] ?? $d);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnArgument(2);
        return new AiDraftController($request, $session, $rooms ?? $this->createMock(RoomService::class), $this->createMock(PaceService::class),
            $this->draft($policy), $this->l10n, $this->createMock(ILimiter::class), new Limits($appConfig), $policy);
    }
}
