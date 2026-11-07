<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

/**
 * The rows a replay created itself under other ids than the recording run (e.g. a throwaway record and its owner
 * rows). Each pair names the replay's id and the recorded id of the same row. A player answering a replayed call from
 * the recording ({@see ArgusTransportRecording}) matches the request in the recording's ids and hands the recorded
 * answer back in the replay's ids — so a request whose path names the replay's row still finds its recorded answer, and
 * the caller reads its own row in that answer.
 *
 * Only whole numbers of at least {@see MIN_ID_DIGITS} digits that are a declared id are replaced; every other number
 * stays. Pure.
 */
class RecordedRunIdCounterparts
{
    /** A number shorter than this is never read as an id */
    public const int MIN_ID_DIGITS = 6;

    /** @var string[] replay id → recorded id */
    public array $recordedIdsByReplayId = [];

    /** @var string[] recorded id → replay id */
    public array $replayIdsByRecordedId = [];

    public function addCounterpart(int|string $replayId, int|string $recordedId): void
    {
        $this->recordedIdsByReplayId[(string)$replayId] = (string)$recordedId;
        $this->replayIdsByRecordedId[(string)$recordedId] = (string)$replayId;
    }

    /** A text the replay sent, in the recording's ids (for matching it against the recorded calls) */
    public function translateReplayTextToRecorded(string $text): string
    {
        if ($this->recordedIdsByReplayId === []) {
            return $text;
        }
        return (string)preg_replace_callback(self::buildIdPattern(), [$this, 'translateReplayIdMatch'], $text);
    }

    /** A text the recording holds, in the replay's ids (for handing a recorded answer to the replay) */
    public function translateRecordedTextToReplay(string $text): string
    {
        if ($this->replayIdsByRecordedId === []) {
            return $text;
        }
        return (string)preg_replace_callback(self::buildIdPattern(), [$this, 'translateRecordedIdMatch'], $text);
    }

    protected static function buildIdPattern(): string
    {
        return '/(?<![\d.])\d{' . self::MIN_ID_DIGITS . ',}(?![\d.])/';
    }

    /** @param string[] $numberMatch */
    protected function translateReplayIdMatch(array $numberMatch): string
    {
        return $this->recordedIdsByReplayId[$numberMatch[0]] ?? $numberMatch[0];
    }

    /** @param string[] $numberMatch */
    protected function translateRecordedIdMatch(array $numberMatch): string
    {
        return $this->replayIdsByRecordedId[$numberMatch[0]] ?? $numberMatch[0];
    }
}
