<?php

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Pulse\Service;

use OCA\Pulse\Db\Poll;
use OCA\Pulse\Db\PollMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ISecureRandom;

/**
 * Images for questions. They live in the app's AppData area (not in the web root,
 * not in anyone's files) and are served through dedicated endpoints.
 *
 * Uploaded images are ALWAYS re-encoded instead of passed through: that drops
 * EXIF data, embedded payloads and polyglot files and, as a side effect, caps
 * the edge length. What comes out here is a PNG or JPEG freshly written by
 * GD — nothing else.
 *
 * Storage: AppData counts towards no user quota, so every account has its
 * own image budget (Limits::IMAGE_BYTES_PER_OWNER, the sum of the stored
 * files of all its rooms), checked on upload and on copy. A re-encoded PNG
 * can be several times larger than the uploaded WebP, which is why the
 * budget counts what is stored, not what was sent. Parallel uploads and
 * copies of one account each check before any of them has written, so each
 * also counts again once its own image is referenced, and undoes it when the
 * account is over the budget (store(), RoomService::duplicateRoom) — then
 * possibly all of them, never too many.
 *
 * Every write to the `image` column is a compare-and-set against the value
 * read before (swapImage): two uploads to the same question at the same
 * time would otherwise both write a file and only one would stay
 * referenced — the other one would be a file that no deletion path (room,
 * account, cleanup job) ever finds, because they all start from the rows.
 * The loser deletes its own file again. What still slips through (a crash
 * between writing the file and the row) is removed by sweepOrphans in the
 * daily cleanup job.
 */
class PollImageService {
    /** Upload limit (bytes). PHP itself allows much more here. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Longest edge after downscaling — enough for any projector. */
    private const MAX_EDGE = 1600;
    /** Guard against "decompression bombs": pixels before downscaling. */
    private const MAX_PIXELS = 50_000_000;
    private const FOLDER = 'polls';

    private const ACCEPTED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'png',   // stored as PNG (animation is lost)
        IMAGETYPE_WEBP => 'png',
    ];

    public function __construct(
        private PollMapper $pollMapper,
        private IAppDataFactory $appDataFactory,
        private ISecureRandom $secureRandom,
        private IL10N $l10n,
        private IDBConnection $db,
        private Limits $limits,
        private ITimeFactory $timeFactory,
    ) {
    }

    /**
     * Validate an uploaded image, re-encode it, store it and record it on the
     * question. A previous image of the same question is replaced.
     *
     * @param array{tmp_name?:string, size?:int, error?:int} $upload $_FILES entry
     * @return Poll the updated question
     * @throws \InvalidArgumentException for a broken/too large/missing image,
     *                                   or when the owner's image budget is used up
     * @throws ConflictException         another upload replaced the image meanwhile
     */
    public function store(Poll $poll, array $upload): Poll {
        // `image[]=…` (or image[a]) yields a list per field instead of a
        // value — that is not an image (without this check, (int) on it would be 1 =
        // UPLOAD_ERR_INI_SIZE, i.e. "too large").
        if (!is_int($upload['error'] ?? UPLOAD_ERR_NO_FILE) || !is_string($upload['tmp_name'] ?? '')) {
            throw new \InvalidArgumentException($this->l10n->t('No image received.'));
        }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException($this->l10n->t('The image is too large.'));
        }
        if ($error !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
            throw new \InvalidArgumentException($this->l10n->t('No image received.'));
        }
        $path = (string)$upload['tmp_name'];
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t('Please upload an image of up to 5 MB.'));
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException($this->l10n->t('No image received.'));
        }
        // Do not trust the client's Content-Type — ask the file itself.
        $info = @getimagesizefromstring($raw);
        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            throw new \InvalidArgumentException($this->l10n->t('Only PNG, JPEG, GIF or WebP.'));
        }
        if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException($this->l10n->t('The image has too many pixels.'));
        }

        // Budget: the image this one replaces is freed, so it does not count.
        // Checked once before the (expensive) re-encoding — a full budget
        // costs no GD run — and once more with the exact size afterwards.
        $owner = $this->ownerOf($poll->getRoomId());
        $sizes = $this->storedSizes();
        $remaining = $this->limits->get(Limits::IMAGE_BYTES_PER_OWNER)
            - $this->usage($owner, $sizes)
            + $this->sizeIn($sizes, $poll);
        if ($remaining <= 0) {
            throw $this->budgetExceeded();
        }

        [$data, $ext] = $this->reencode($raw, (int)$info[2]);
        if (strlen($data) > $remaining) {
            throw $this->budgetExceeded();
        }

        // Write first, then switch the row over, then remove the old file:
        // if writing fails, the previous image is kept.
        $old = $this->fileName($poll);
        $name = $this->newName($ext);
        $this->folder()->newFile($this->storedName($poll->getId(), $name), $data);
        if (!$this->swapImage($poll->getId(), $poll->imageOrEmpty(), $name)) {
            // Another upload (second tab, double click) switched the row in
            // the meantime — its image stays, ours goes.
            $this->deleteFile($poll->getId(), $name);
            throw new ConflictException($this->l10n->t('The image was changed at the same time elsewhere. Please try again.'));
        }
        // Counted again now that the image is referenced: a parallel upload
        // or copy of the same account may have written in the meantime. The
        // replaced image no longer counts, and is still there to go back to.
        if ($this->remainingBudget($owner) < 0) {
            if ($this->swapImage($poll->getId(), $name, $poll->imageOrEmpty())) {
                $this->deleteFile($poll->getId(), $name);
            }
            throw $this->budgetExceeded();
        }
        $poll->setImage($name);
        $poll->resetUpdatedFields();
        if ($old !== '' && $old !== $name) {
            $this->deleteFile($poll->getId(), $old);
        }
        return $poll;
    }

    /** Remove the image (file + record on the question). A no-op without an image. */
    public function remove(Poll $poll): Poll {
        $name = $this->fileName($poll);
        if ($name === '') {
            return $poll;
        }
        // Row first (compare-and-set), then the file: if an upload replaced
        // the image meanwhile, its new file stays referenced and the upload
        // deletes the old one itself. A file left behind by a failing delete
        // is picked up by sweepOrphans.
        if ($this->swapImage($poll->getId(), $poll->imageOrEmpty(), '')) {
            $this->deleteFile($poll->getId(), $name);
        }
        $poll->setImage('');
        $poll->resetUpdatedFields();
        return $poll;
    }

    /**
     * Read a question's image (for serving).
     *
     * @return ?array{content:string, mime:string, name:string}
     */
    public function read(Poll $poll): ?array {
        $name = $this->fileName($poll);
        if ($name === '') {
            return null;
        }
        try {
            $file = $this->folder()->getFile($this->storedName($poll->getId(), $name));
            return [
                'content' => $file->getContent(),
                'mime' => str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/png',
                'name' => $name,
            ];
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Copy the image along when duplicating — the copy gets its own file,
     * so deleting one room does not take the other room's image with it.
     *
     * $remaining: the owner's image budget still free, when the caller
     * (RoomService::duplicateRoom) already computed it for the whole room;
     * null = compute it here for the owner of the target question.
     *
     * @return int bytes written (0 = nothing to copy, or the target question
     *             vanished / got an image of its own in the meantime)
     * @throws \InvalidArgumentException the owner's image budget is used up
     */
    public function copy(Poll $src, Poll $dst, ?int $remaining = null): int {
        $data = $this->read($src);
        if ($data === null) {
            return 0;
        }
        $bytes = strlen($data['content']);
        $remaining ??= $this->remainingBudget($this->ownerOf($dst->getRoomId()));
        if ($bytes > $remaining) {
            throw $this->budgetExceeded();
        }
        $ext = str_ends_with($data['name'], '.jpg') ? 'jpg' : 'png';
        $name = $this->newName($ext);
        $this->folder()->newFile($this->storedName($dst->getId(), $name), $data['content']);
        if (!$this->swapImage($dst->getId(), $dst->imageOrEmpty(), $name)) {
            $this->deleteFile($dst->getId(), $name);
            return 0;
        }
        $dst->setImage($name);
        $dst->resetUpdatedFields();
        return $bytes;
    }

    /**
     * Clean up when a question (or a whole room) goes away. The question is
     * NOT written here any more — it is about to be deleted anyway.
     */
    public function discard(Poll $poll): void {
        $name = $this->fileName($poll);
        if ($name !== '') {
            $this->deleteFile($poll->getId(), $name);
        }
    }

    // ── Budget ──────────────────────────────────────────────────────────────

    /** Bytes of the stored image of one question (0 = none or file missing). */
    public function sizeOf(Poll $poll): int {
        $name = $this->fileName($poll);
        if ($name === '') {
            return 0;
        }
        try {
            return (int)$this->folder()->getFile($this->storedName($poll->getId(), $name))->getSize();
        } catch (NotFoundException) {
            return 0;
        }
    }

    /** Image budget of an account that is still free (bytes, may be negative). */
    public function remainingBudget(string $ownerUid): int {
        return $this->limits->get(Limits::IMAGE_BYTES_PER_OWNER) - $this->usage($ownerUid, $this->storedSizes());
    }

    /** The exception for a used-up budget — the same text everywhere. */
    public function budgetExceeded(): \InvalidArgumentException {
        $mb = (int)ceil($this->limits->get(Limits::IMAGE_BYTES_PER_OWNER) / (1024 * 1024));
        return new \InvalidArgumentException($this->l10n->t('The images of your rooms have reached the storage limit of %d MB. Remove images or rooms you no longer need.', [$mb]));
    }

    // ── Cleanup ─────────────────────────────────────────────────────────────

    /**
     * Delete files in the image folder that no question references (daily
     * cleanup job). Only files older than $minAgeSeconds: an upload writes
     * its file before it switches the row over, and that moment must never
     * look like an orphan.
     *
     * @return int number of deleted files
     */
    public function sweepOrphans(int $minAgeSeconds): int {
        $folder = $this->existingFolder();
        if ($folder === null) {
            return 0; // no image was ever stored
        }
        $cutoff = $this->timeFactory->getTime() - $minAgeSeconds;
        // Listing first, references second: a file that shows up in the
        // listing and is referenced only afterwards is younger than the cutoff.
        $files = $folder->getDirectoryListing();
        $referenced = $this->referencedNames();
        $deleted = 0;
        foreach ($files as $file) {
            if (isset($referenced[$file->getName()]) || $file->getMTime() >= $cutoff) {
                continue;
            }
            try {
                $file->delete();
                $deleted++;
            } catch (NotFoundException) {
                // removed concurrently — fine
            }
        }
        return $deleted;
    }

    // ── internal ────────────────────────────────────────────────────────────

    /**
     * Re-encode + downscale to MAX_EDGE. JPEG stays JPEG (photos), everything
     * else becomes PNG (transparency is preserved).
     *
     * @return array{0:string, 1:string} raw data + file extension
     */
    private function reencode(string $raw, int $type): array {
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            throw new \InvalidArgumentException($this->l10n->t('The image could not be read.'));
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = max($w, $h) > self::MAX_EDGE ? self::MAX_EDGE / max($w, $h) : 1.0;
        if ($scale < 1.0) {
            $resized = imagescale($img, (int)round($w * $scale), (int)round($h * $scale));
            if ($resized !== false) {
                // GdImage objects free themselves with their last reference
                // (imagedestroy has done nothing since PHP 8.0 and is deprecated in 8.5).
                $img = $resized;
            }
        }

        $jpeg = $type === IMAGETYPE_JPEG;
        if (!$jpeg) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        ob_start();
        $ok = $jpeg ? imagejpeg($img, null, 85) : imagepng($img, null, 6);
        $data = (string)ob_get_clean();
        if (!$ok || $data === '') {
            throw new \InvalidArgumentException($this->l10n->t('The image could not be processed.'));
        }
        return [$data, $jpeg ? 'jpg' : 'png'];
    }

    /**
     * Compare-and-set of the `image` column: switch question $pollId from
     * $old to $new, but only if it still holds $old. false = someone else
     * changed it first, or the question is gone.
     */
    private function swapImage(int $pollId, string $old, string $new): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update('pulse_polls')
            ->set('image', $qb->createNamedParameter($new))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
        if ($old === '') {
            // The column is nullable, and Oracle stores '' as NULL.
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('image'),
                $qb->expr()->eq('image', $qb->createNamedParameter('')),
            ));
        } else {
            $qb->andWhere($qb->expr()->eq('image', $qb->createNamedParameter($old)));
        }
        return $qb->executeStatement() > 0;
    }

    /** Owner of a room ('' if the room is gone). */
    private function ownerOf(int $roomId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid')
            ->from('pulse_rooms')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $uid = $result->fetchOne();
        $result->closeCursor();
        return is_string($uid) ? $uid : '';
    }

    /**
     * Bytes the images of all rooms of an account take up: every stored
     * file a question of theirs references.
     *
     * @param array<string,int> $sizes stored name => bytes (storedSizes)
     */
    private function usage(string $ownerUid, array $sizes): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.id', 'p.image')
            ->from('pulse_polls', 'p')
            ->innerJoin('p', 'pulse_rooms', 'r', $qb->expr()->eq('r.id', 'p.room_id'))
            ->where($qb->expr()->eq('r.owner_uid', $qb->createNamedParameter($ownerUid)))
            ->andWhere($qb->expr()->isNotNull('p.image'));
        $result = $qb->executeQuery();
        $sum = 0;
        while ($row = $result->fetch()) {
            $stored = $this->storedName((int)$row['id'], (string)$row['image']);
            $sum += $sizes[$stored] ?? 0;
        }
        $result->closeCursor();
        return $sum;
    }

    /** @param array<string,int> $sizes */
    private function sizeIn(array $sizes, Poll $poll): int {
        $name = $this->fileName($poll);
        return $name === '' ? 0 : ($sizes[$this->storedName($poll->getId(), $name)] ?? 0);
    }

    /**
     * Sizes of all stored files, one folder listing instead of one lookup
     * per image.
     *
     * @return array<string,int> stored name => bytes
     */
    private function storedSizes(): array {
        $sizes = [];
        foreach ($this->existingFolder()?->getDirectoryListing() ?? [] as $file) {
            $sizes[$file->getName()] = (int)$file->getSize();
        }
        return $sizes;
    }

    /** @return array<string,true> stored names that a question references */
    private function referencedNames(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'image')
            ->from('pulse_polls')
            ->where($qb->expr()->isNotNull('image'));
        $result = $qb->executeQuery();
        $names = [];
        while ($row = $result->fetch()) {
            $image = (string)$row['image'];
            if ($image !== '') {
                $names[$this->storedName((int)$row['id'], $image)] = true;
            }
        }
        $result->closeCursor();
        return $names;
    }

    private function newName(string $ext): string {
        return $this->secureRandom->generate(16, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS) . '.' . $ext;
    }

    private function fileName(Poll $poll): string {
        $name = (string)($poll->getImage() ?? '');
        // Accept only the expected pattern — the value ends up in a file name.
        return preg_match('/^[a-z0-9]{16}\.(png|jpg)$/', $name) === 1 ? $name : '';
    }

    private function storedName(int $pollId, string $name): string {
        return $pollId . '-' . $name;
    }

    private function deleteFile(int $pollId, string $name): void {
        try {
            $this->folder()->getFile($this->storedName($pollId, $name))->delete();
        } catch (NotFoundException) {
            // already gone — nothing to do
        }
    }

    private function folder(): ISimpleFolder {
        return $this->existingFolder() ?? $this->appDataFactory->get('pulse')->newFolder(self::FOLDER);
    }

    /** The image folder, or null while no image was ever stored (reads only). */
    private function existingFolder(): ?ISimpleFolder {
        try {
            return $this->appDataFactory->get('pulse')->getFolder(self::FOLDER);
        } catch (NotFoundException) {
            return null;
        }
    }
}
