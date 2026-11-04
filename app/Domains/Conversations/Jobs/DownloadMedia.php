<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Jobs;

use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Saves a guest's voice note, image or document before Meta's download
 * link expires. Files are private to the hotel.
 */
final class DownloadMedia implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public const MAX_BYTES = 16 * 1024 * 1024;

    public int $tries = 4;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $messageId) {}

    public function handle(GraphClient $graph): void
    {
        $message = Message::query()->find($this->messageId);
        $account = WhatsAppAccount::query()->first();
        $media = $message?->media;

        if ($message === null || $account === null || $account->token === null || ! is_array($media) || ($media['media_id'] ?? '') === '' || isset($media['path'])) {
            return;
        }

        try {
            $file = $graph->downloadMedia((string) $media['media_id'], $account->token, self::MAX_BYTES);
        } catch (GraphException $exception) {
            $message->forceFill(['media' => [...$media, 'error' => mb_substr($exception->getMessage(), 0, 200)]])->save();

            if ($exception->metaCode === 413) {
                $this->replyIfVoiceNote($message);

                return;
            }

            throw $exception;
        }

        $extension = self::extension($file['mime_type']);
        $path = sprintf('whatsapp/%s/%s.%s', $message->hotel_id, $message->id, $extension);
        Storage::disk('local')->put($path, $file['contents']);

        $message->forceFill(['media' => [
            ...$media,
            'path' => $path,
            'size' => strlen($file['contents']),
            'mime_type' => $file['mime_type'] !== '' ? $file['mime_type'] : ($media['mime_type'] ?? ''),
            'error' => null,
        ]])->save();

        if ($message->type === 'audio') {
            TranscribeMessage::dispatch($message->id);
        }
    }

    public function failed(): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message !== null) {
            $this->replyIfVoiceNote($message);
        }
    }

    /**
     * A voice note that cannot be downloaded cannot be transcribed: let the
     * reply job pass the conversation to staff.
     */
    private function replyIfVoiceNote(Message $message): void
    {
        if ($message->type === 'audio') {
            ReplyToGuest::schedule($message);
        }
    }

    public static function extension(string $mime): string
    {
        $base = strtolower(trim(explode(';', $mime)[0]));

        return match ($base) {
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/amr' => 'amr',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
