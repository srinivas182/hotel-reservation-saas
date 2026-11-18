<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use App\Domains\Ai\AiTask;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Assistant\Engine\Guardrails;
use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Models\ListingImport;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Reads the sources of an import and writes its draft. Each source can
 * fail on its own (a site that blocks robots, a photo the AI cannot read):
 * the owner gets a note for it and whatever the other sources gave.
 */
final class ListingImporter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiGateway $gateway,
        private readonly SafeFetcher $fetcher,
    ) {}

    public function analyse(ListingImport $import): ListingImport
    {
        $notes = [];
        $pin = null;
        $structured = [];
        $parts = [];

        if ($import->maps_url !== null) {
            [$pin, $note] = $this->pin($import->maps_url);
            $notes = [...$notes, ...($note === null ? [] : [$note])];
            if ($pin !== null && $pin['name'] !== null) {
                $parts[] = "[Google Maps]\nPlace name: {$pin['name']}";
            }
        }

        if ($import->website_url !== null) {
            try {
                $page = $this->fetcher->page($import->website_url);
                $read = PageReader::read($page['body']);
                $structured = $read['structured'];
                $parts[] = "[Website: {$page['url']}]\n".implode("\n", array_filter([
                    $read['title'] === null ? null : 'Title: '.$read['title'],
                    $read['description'] === null ? null : 'Description: '.$read['description'],
                    $structured === [] ? null : 'Structured data: '.json_encode(array_diff_key($structured, ['rank' => true]), JSON_UNESCAPED_UNICODE),
                    $read['text'],
                ]));
            } catch (UnreadableSource $e) {
                $notes[] = __('Website: :reason', ['reason' => $e->getMessage()]);
            }
        }

        if ($import->pasted_text !== null && trim($import->pasted_text) !== '') {
            $parts[] = "[Text pasted by the owner]\n".trim($import->pasted_text);
        }

        $attachments = $this->attachments($import);
        $aiText = null;

        if ($parts !== [] || $attachments !== []) {
            $prompt = Guardrails::redact(implode("\n\n", $parts) ?: 'Read the attached photos and documents.');
            $aiText = $this->ask($prompt, $attachments, $parts !== [], $notes);
        }

        $draft = ListingDraft::build($aiText, $structured, $pin, $notes);

        if (ListingDraft::isEmpty($draft)) {
            $import->forceFill(['status' => 'failed', 'draft' => $draft, 'error' => mb_substr($notes[0] ?? (string) __('Nothing useful was found. Try another link, paste the text, or add a photo of your rate card.'), 0, 500)])->save();
        } else {
            $import->forceFill(['status' => 'ready', 'draft' => $draft, 'error' => null])->save();
        }

        Audit::record('onboarding.listing_analysed', 'Set-up import read', subject: $this->context->hotel(), properties: [
            'import' => $import->id,
            'status' => $import->status,
            'sources' => array_keys(array_filter(['website' => $import->website_url, 'maps' => $import->maps_url, 'text' => $import->pasted_text, 'files' => $import->files])),
            'rooms' => count($draft['rooms']),
            'entries' => count($draft['entries']),
        ]);

        return $import;
    }

    /**
     * @return array{0: array{latitude: float, longitude: float, name: string|null}|null, 1: string|null}
     */
    private function pin(string $url): array
    {
        try {
            $long = MapsLink::isShortLink($url) ? $this->fetcher->expand($url) : $url;
        } catch (UnreadableSource $e) {
            return [null, (string) __('Google Maps: :reason', ['reason' => $e->getMessage()])];
        }

        $pin = MapsLink::parse($long);

        return [$pin, $pin === null ? (string) __('Google Maps: no map pin in this link. Open your hotel in Google Maps, press Share and copy the link.') : null];
    }

    /**
     * @param  list<\Laravel\Ai\Files\File>  $attachments
     * @param  list<string>  $notes
     */
    private function ask(string $prompt, array $attachments, bool $hasText, array &$notes): ?string
    {
        $hotel = $this->context->hotel();
        $agent = new ListingAgent($hotel->name, $hotel->currency);

        try {
            return $this->gateway->prompt(AiTask::Translation, $agent, $prompt, null, $attachments)->response->text;
        } catch (Throwable $e) {
            Log::warning('Set-up import: AI reading failed', ['error' => Str::limit($e->getMessage(), 300), 'attachments' => count($attachments)]);
        }

        // Photos are the part most likely to fail (model without vision, file too heavy): retry with the text alone.
        if ($attachments !== [] && $hasText) {
            $notes[] = (string) __('Photos and PDFs: they could not be read right now. Try again later, or type the prices yourself.');

            try {
                return $this->gateway->prompt(AiTask::Translation, $agent, $prompt)->response->text;
            } catch (Throwable $e) {
                Log::warning('Set-up import: AI reading failed again', ['error' => Str::limit($e->getMessage(), 300)]);
            }
        }

        $notes[] = (string) __('The assistant could not read your material right now. What the website itself publishes is shown; try again later for the rest.');

        return null;
    }

    /**
     * @return list<\Laravel\Ai\Files\File>
     */
    private function attachments(ListingImport $import): array
    {
        $files = [];

        foreach ($import->files ?? [] as $file) {
            $files[] = $file['mime'] === 'application/pdf'
                ? Document::fromStorage($file['path'], 'local')
                : Image::fromStorage($file['path'], 'local');
        }

        return $files;
    }
}
