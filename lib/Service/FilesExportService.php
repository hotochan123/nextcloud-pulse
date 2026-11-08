<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Room;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDateTimeZone;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Saves a room's results CSV (or its AI summary) into the owner's Nextcloud files, folder
 * "Pulse" (created when missing), as "<room name> – Results <date>.csv"
 * (or "… – Summary <date>.md").
 *
 * The content is exactly the summary's CSV download (StateService::exportCsv,
 * formula guard included). An existing file is never overwritten: a clash
 * gets " (2)", " (3)" … through Folder::getNonExistingName. The room name
 * comes from the moderator, so it is cleaned into one plain file name and
 * can never name a path outside the folder.
 */
class FilesExportService {
    public const FOLDER = 'Pulse';

    public function __construct(
        private IRootFolder $rootFolder,
        private StateService $stateService,
        private IFilenameValidator $filenameValidator,
        private IDateTimeZone $dateTimeZone,
        private ITimeFactory $timeFactory,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{fileId: int, name: string, path: string}
     * @throws FilesExportException with a message for the moderator
     */
    public function saveResults(Room $room, string $uid): array {
        return $this->save($uid, $this->fileName($room), $this->stateService->exportCsv($room));
    }

    /**
     * The AI summary (Markdown, as the moderator edited it) as
     * "<room name> – Summary <date>.md", same folder and clash rule.
     *
     * @return array{fileId: int, name: string, path: string}
     * @throws FilesExportException with a message for the moderator
     */
    public function saveSummary(Room $room, string $uid, string $markdown): array {
        return $this->save($uid, $this->summaryFileName($room), $markdown);
    }

    /** @return array{fileId: int, name: string, path: string} */
    private function save(string $uid, string $wanted, string $content): array {
        try {
            $folder = $this->folder($this->rootFolder->getUserFolder($uid));
            $name = $folder->getNonExistingName($wanted);
            $file = $folder->newFile($name, $content);
        } catch (FilesExportException $e) {
            throw $e;
        } catch (NotPermittedException $e) {
            throw new FilesExportException($this->l10n->t('You cannot save files in your Files.'), 0, $e);
        } catch (\Exception $e) {
            // Quota, storage not available, locked file, no home folder …:
            // a message instead of a 500.
            $this->logger->warning('Pulse: could not save a file into Files', ['exception' => $e]);
            throw new FilesExportException($this->l10n->t('Could not save the file in your Files.'), 0, $e);
        }
        return [
            'fileId' => (int)$file->getId(),
            'name' => $file->getName(),
            'path' => '/' . self::FOLDER . '/' . $file->getName(),
        ];
    }

    /** "<room name> – Results <YYYY-MM-DD>.csv", the date in the user's time zone. */
    public function fileName(Room $room): string {
        return $this->named($room, ' – ' . $this->l10n->t('Results') . ' ' . $this->today() . '.csv');
    }

    /** "<room name> – Summary <YYYY-MM-DD>.md", the date in the user's time zone. */
    public function summaryFileName(Room $room): string {
        return $this->named($room, ' – ' . $this->l10n->t('Summary') . ' ' . $this->today() . '.md');
    }

    /** Today in the user's time zone, YYYY-MM-DD. */
    public function today(): string {
        return $this->timeFactory->getDateTime('now', $this->dateTimeZone->getTimeZone())->format('Y-m-d');
    }

    private function named(Room $room, string $suffix): string {
        $name = $this->clean($room->titleOrEmpty() . $suffix, strlen($suffix));
        // A title that cleans away to nothing usable falls back to the code.
        return $name ?? $room->getCode() . $suffix;
    }

    /**
     * One plain file name, or null when the title leaves nothing usable.
     * Path separators and control characters go first (whatever the
     * instance forbids), leading dots and blanks too (no hidden file).
     */
    private function clean(string $name, int $suffixLength): ?string {
        $name = (string)preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name);
        $name = ltrim($name, " .\t");
        try {
            $name = $this->filenameValidator->sanitizeFilename($name);
        } catch (\Exception) {
            return null;
        }
        $name = ltrim($name, ' .');
        // Nothing of the title left: only the suffix (or less) remains.
        if (strlen($name) <= $suffixLength - 1 || str_contains($name, '/')
            || !$this->filenameValidator->isFilenameValid($name)) {
            return null;
        }
        return $name;
    }

    /** The "Pulse" folder in the user's files, created when missing. */
    private function folder(Folder $home): Folder {
        if (!$home->nodeExists(self::FOLDER)) {
            return $home->newFolder(self::FOLDER);
        }
        $node = $home->get(self::FOLDER);
        if (!$node instanceof Folder) {
            throw new FilesExportException($this->l10n->t('A file named “Pulse” is in the way in your Files.'));
        }
        return $node;
    }
}
