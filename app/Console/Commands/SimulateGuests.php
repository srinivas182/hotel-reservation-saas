<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends signed guest messages to this application's WhatsApp webhook, as
 * Meta would. Staging only (needs SIMULATION_MODE).
 */
#[Signature('simulation:guests {phone_number_id : The hotel number\'s phone number ID} {--conversations=10} {--messages=3} {--url= : Defaults to APP_URL}')]
#[Description('Simulate guests writing to a hotel on WhatsApp (staging only)')]
final class SimulateGuests extends Command
{
    private const LINES = [
        'Bonjour', 'Vous avez une chambre pour ce week-end ?', 'C’est combien la nuit ?', 'Je voudrais réserver',
        'Hello, do you have a room tonight?', 'What is the price?', 'Merci beaucoup', 'Le petit-déjeuner est inclus ?',
    ];

    public function handle(): int
    {
        if (! config('simulation.enabled')) {
            $this->components->error('Simulation mode is off (SIMULATION_MODE=false).');

            return self::FAILURE;
        }

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/webhooks/whatsapp';
        $sent = 0;

        foreach (range(1, (int) $this->option('conversations')) as $c) {
            $from = '22507'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

            foreach (range(1, (int) $this->option('messages')) as $m) {
                $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'sim-waba', 'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => (string) $this->argument('phone_number_id')],
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Guest '.$c]]],
                        'messages' => [['from' => $from, 'id' => 'wamid.SIMIN'.Str::ulid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => self::LINES[array_rand(self::LINES)]]]],
                    ],
                ]]]]], JSON_THROW_ON_ERROR);

                Http::withBody($body, 'application/json')
                    ->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) config('whatsapp.app_secret'))])
                    ->post($url)
                    ->throw();
                $sent++;
            }
        }

        $this->components->info("{$sent} simulated guest message(s) sent.");

        return self::SUCCESS;
    }
}
