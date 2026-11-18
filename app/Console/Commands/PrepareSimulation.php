<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ai\AiDriver;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Ai\ModelStatus;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prepares a staging database for load tests and failure drills: the demo
 * hotels, a simulated WhatsApp number for First Class Lodge, a published
 * information pack and the assistant in automatic mode. Staging only.
 */
#[Signature('simulation:prepare {--report : Only print message counts} {--expect-replies : With --report, fail unless every conversation got a reply and no send failed}')]
#[Description('Prepare demo data for simulation (staging only)')]
final class PrepareSimulation extends Command
{
    public const PHONE_NUMBER_ID = 'SIM-5550001';

    public function handle(TenantContext $context): int
    {
        if (! config('simulation.enabled')) {
            $this->components->error('Simulation mode is off (SIMULATION_MODE=false).');

            return self::FAILURE;
        }

        if (! $this->option('report')) {
            $this->call('permissions:sync');
        }

        if (! Hotel::query()->where('slug', 'first-class-lodge')->exists()) {
            $this->call('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true]);
        }

        $hotel = Hotel::query()->where('slug', 'first-class-lodge')->firstOrFail();

        if (! $this->option('report')) {
            $this->simulatedAiModel();
        }

        $ok = $context->run($hotel, function (): bool {
            if ($this->option('report')) {
                $conversations = Conversation::query()->count();
                $answered = Conversation::query()->whereHas('messages', fn ($q) => $q->where('direction', 'out'))->count();
                $failed = Message::query()->where('status', 'failed')->count();
                $duplicates = Message::query()->whereNotNull('wamid')->count() - Message::query()->whereNotNull('wamid')->distinct()->count('wamid');

                $this->table(['Measure', 'Count'], [
                    ['Conversations', $conversations],
                    ['Conversations answered', $answered],
                    ['Guest messages', Message::query()->where('direction', 'in')->count()],
                    ['Replies', Message::query()->where('direction', 'out')->count()],
                    ['Failed sends', $failed],
                    ['Duplicated messages', $duplicates],
                ]);

                return ! $this->option('expect-replies') || ($conversations > 0 && $answered === $conversations && $failed === 0 && $duplicates === 0);
            }

            WhatsAppAccount::query()->updateOrCreate([], [
                'waba_id' => 'sim-waba',
                'phone_number_id' => self::PHONE_NUMBER_ID,
                'display_phone' => '+225 00 00 00 00',
                'status' => AccountStatus::Connected,
                'token' => 'simulated-token',
            ]);

            if (KnowledgeEntry::query()->doesntExist()) {
                KnowledgeEntry::query()->create(['locale' => 'fr', 'section' => 'payments', 'content' => 'Paiement en espèces, Orange Money ou Wave.']);
            }
            app(KnowledgePack::class)->publish();

            HotelAssistantSettings::current()->forceFill([
                'mode' => AssistantMode::Automatic,
                'readiness_override_at' => now(),
                'readiness_override_reason' => 'Simulation (staging)',
            ])->save();

            return true;
        });

        if (! $this->option('report')) {
            // The simulated staff have read the current legal texts (S14).
            foreach ($hotel->members()->get() as $member) {
                app(\App\Domains\Onboarding\Legal::class)->accept($member, app(\App\Domains\Onboarding\Legal::class)->missing($member), '127.0.0.1', $hotel->id);
            }
        }

        if (! $ok) {
            $this->components->error('Not every conversation was answered, or messages failed or were duplicated.');

            return self::FAILURE;
        }

        if (! $this->option('report')) {
            $this->components->info('Ready. Phone number ID: '.self::PHONE_NUMBER_ID.' · reception login: reception.lodge@example.com / password');
        }

        return self::SUCCESS;
    }

    /**
     * Staging without AI keys: a simulated provider and model, so the AI
     * gateway has a model to choose. In simulation every agent is faked
     * (SimulationServiceProvider), so nothing reaches a real provider. Only
     * fills an empty slot: real models set up on staging stay in charge.
     * The model stays in "testing", so the launch checklist is not fooled.
     */
    private function simulatedAiModel(): void
    {
        $settings = AiPlatformSettings::current();

        if ($settings->assistant_model_id !== null) {
            return;
        }

        $provider = AiProvider::query()->firstOrCreate(['name' => 'Simulation (staging)'], [
            'driver' => AiDriver::Anthropic, 'credentials' => ['key' => 'simulated'], 'active' => true,
        ]);
        $model = AiModel::query()->firstOrCreate(['ai_provider_id' => $provider->id, 'model' => 'simulated-model'], [
            'label' => 'Simulated', 'capabilities' => ['chat', 'tools', 'vision'], 'status' => ModelStatus::Testing,
            'input_cost_per_mtok' => 0, 'output_cost_per_mtok' => 0,
        ]);

        $settings->forceFill(['assistant_model_id' => $model->id])->save();
    }
}
