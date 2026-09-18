<?php

namespace App\Media;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * WhatsApp requires ogg/opus voice notes; the browser's MediaRecorder often
 * produces webm/opus instead (spec §1.4). When `CRM_FFMPEG_PATH` is
 * configured, the outbound send job transcodes to ogg/opus before uploading;
 * otherwise the caller decides how to fail (WhatsAppAdapter fails the send
 * on the bubble with the spec's Arabic message, never a 422 at send time).
 */
final class VoiceTranscoder
{
    /**
     * The whole outbound send job (SendOutboundMessage::$timeout = 80s) is
     * shared with the WhatsApp media upload (30s, single try), the media
     * /messages send (10s × up to 2 tries), and an optional caption follow-up
     * (10s, single try) — worst case ≈ 75s. ffmpeg gets a hard cap well under
     * that so a stuck transcode can never be the reason the job times out.
     */
    private const TIMEOUT_SECONDS = 15;

    public function available(): bool
    {
        $path = config('crm.media.ffmpeg_path');

        return is_string($path) && $path !== '';
    }

    /**
     * @return string|null the temp .ogg path (caller must delete it), or null on failure/unavailable
     */
    public function toOggOpus(string $inputPath): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $output = storage_path('app/tmp/'.Str::uuid().'.ogg');
        File::ensureDirectoryExists(dirname($output));

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
                (string) config('crm.media.ffmpeg_path'), '-y', '-i', $inputPath, '-vn', '-c:a', 'libopus', '-b:a', '32k', $output,
            ]);

            if ($result->successful() && is_file($output)) {
                return $output;
            }
        } catch (ProcessTimedOutException|ProcessFailedException) {
            // Fall through to the cleanup below — never leave a partial .ogg behind.
        }

        if (is_file($output)) {
            @unlink($output);
        }

        return null;
    }
}
