<?php

declare(strict_types=1);

namespace App\Domains\Launch;

use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\ModelStatus;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Domains\Demo\DemoHotels;
use App\Domains\Help\Models\HelpArticle;
use App\Domains\Launch\Models\LaunchItem;
use App\Domains\Messaging\Email\Models\MailProvider;
use App\Domains\Operations\BackupRun;
use App\Domains\Site\Models\SitePage;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Support\Facades\DB;

/**
 * I: the launch checklist. Items the platform can verify are checked
 * automatically (and cannot be ticked by hand); the others are ticked by
 * their owner with proof. 1.0.0 only when every required item is done.
 */
final class LaunchChecklist
{
    /** key, area, French, English, required, owner role */
    public const ITEMS = [
        ['legal_texts', 'legal', 'Conditions, confidentialité et DPA publiés', 'Terms, privacy notice and DPA published', true, 'super_admin'],
        ['legal_review', 'legal', 'Relecture juridique des textes (version 2 si nécessaire)', 'Lawyer review of the legal texts (version 2 if needed)', false, 'super_admin'],
        ['artci', 'legal', 'Déclaration ARTCI (données personnelles) déposée', 'ARTCI personal data declaration filed', true, 'super_admin'],
        ['payment_live', 'payments', 'Un moyen de paiement en ligne en mode réel', 'An online payment provider in live mode', true, 'finance'],
        ['issuing_company', 'payments', 'Société émettrice des factures complète (RCCM, NCC, banque)', 'Issuing company complete (RCCM, tax ID, bank)', true, 'finance'],
        ['meta_verified', 'whatsapp', 'Application Meta vérifiée', 'Meta app verified', true, 'operations'],
        ['templates_approved', 'whatsapp', 'Modèles WhatsApp approuvés par Meta', 'WhatsApp templates approved by Meta', true, 'operations'],
        ['ai_model', 'whatsapp', 'Un modèle d’IA approuvé et la série de 100 cas réussie', 'An approved AI model and the 100-case suite passed', true, 'operations'],
        ['mail_provider', 'infrastructure', 'Un fournisseur d’e-mail actif', 'An active email provider', true, 'operations'],
        ['production_https', 'infrastructure', 'Production en HTTPS sur le domaine final', 'Production on HTTPS on the final domain', true, 'operations'],
        ['backups', 'infrastructure', 'Sauvegarde réussie dans les dernières 26 heures', 'A successful backup in the last 26 hours', true, 'operations'],
        ['restore_drill', 'infrastructure', 'Exercice de restauration réussi dans les 90 derniers jours', 'A successful restore drill in the last 90 days', true, 'operations'],
        ['staging_drills', 'infrastructure', 'Test de charge et exercices de panne sur la préproduction', 'Load test and outage drills on staging', true, 'operations'],
        ['error_tracking', 'infrastructure', 'Suivi des erreurs branché (Sentry)', 'Error tracking connected (Sentry)', false, 'operations'],
        ['csp_enforced', 'security', 'Politique de sécurité du contenu appliquée', 'Content Security Policy enforced', true, 'operations'],
        ['pentest', 'security', 'Test d’intrusion externe (recommandé sous 3 mois)', 'External penetration test (recommended within 3 months)', false, 'super_admin'],
        ['support_team', 'support', 'Équipe support formée, horaires et délais publiés', 'Support team trained, hours and targets published', true, 'operations'],
        ['help_centre', 'content', 'Centre d’aide : au moins 10 articles publiés', 'Help centre: at least 10 articles published', true, 'sales'],
        ['website', 'content', 'Site web publié (accueil, fonctionnalités, FAQ)', 'Website published (home, features, FAQ)', true, 'sales'],
        ['demo_hotel', 'content', 'Hôtel de démonstration prêt pour les ventes', 'Demo hotel ready for sales', true, 'sales'],
        ['brand', 'content', 'Nom, logo et couleurs du produit choisis', 'Product name, logo and colours chosen', false, 'sales'],
        ['pilot_live', 'pilot', 'Hôtel pilote en service (mode automatique)', 'Pilot hotel live (automatic mode)', true, 'operations'],
    ];

    /**
     * Items verified by the platform, and how.
     *
     * @return array<string, \Closure(): bool>
     */
    public static function automatic(): array
    {
        return [
            'legal_texts' => fn (): bool => DB::table('legal_documents')->whereNotNull('published_at')->distinct()->count('kind') >= 3,
            'payment_live' => fn (): bool => PaymentGateway::query()->where('active', true)->where('mode', 'live')->exists(),
            'templates_approved' => fn (): bool => MessageTemplate::query()->withoutGlobalScopes()->where('status', TemplateStatus::Approved->value)->exists(),
            'ai_model' => fn (): bool => AiModel::query()->where('status', ModelStatus::Approved->value)->exists(),
            'mail_provider' => fn (): bool => MailProvider::query()->where('enabled', true)->exists(),
            'production_https' => fn (): bool => app()->isProduction() && str_starts_with((string) config('app.url'), 'https://'),
            'backups' => fn (): bool => BackupRun::query()->where('kind', 'backup')->where('status', 'ok')->where('created_at', '>=', now()->subHours(26))->exists(),
            'restore_drill' => fn (): bool => BackupRun::query()->where('kind', 'restore_drill')->where('status', 'ok')->where('created_at', '>=', now()->subDays(90))->exists(),
            'error_tracking' => fn (): bool => filled(config('sentry.dsn')),
            'staging_drills' => fn (): bool => \App\Domains\Operations\Drills::allPassed(),
            'csp_enforced' => fn (): bool => (bool) config('security.csp.enforce'),
            'help_centre' => fn (): bool => HelpArticle::query()->where('published', true)->count() >= 10,
            'website' => fn (): bool => SitePage::query()->where('published', true)->whereIn('key', ['home', 'features', 'faq'])->count() === 3,
            'demo_hotel' => fn (): bool => DemoHotels::permanent() !== null,
            'pilot_live' => fn (): bool => Hotel::query()->where('is_demo', false)->where('status', HotelStatus::Active->value)
                ->whereIn('id', DB::table('hotel_assistant_settings')->where('mode', 'automatic')->select('hotel_id'))->exists(),
        ];
    }

    /**
     * Refresh automatic items, then the score.
     *
     * @return array{done: int, required: int, score: int, ready: bool}
     */
    public function refresh(): array
    {
        foreach (self::automatic() as $key => $check) {
            $item = LaunchItem::query()->where('key', $key)->first();
            if ($item === null || $item->status === 'not_needed') {
                continue;
            }
            try {
                $ok = (bool) $check();
            } catch (\Throwable) {
                $ok = false;
            }
            if ($ok && $item->status !== 'done') {
                $item->update(['status' => 'done', 'done_at' => now(), 'proof' => __('Checked automatically by the platform.')]);
            } elseif (! $ok && $item->status === 'done') {
                $item->update(['status' => 'open', 'done_at' => null, 'proof' => null]);
            }
        }

        return $this->score();
    }

    /**
     * @return array{done: int, required: int, score: int, ready: bool}
     */
    public function score(): array
    {
        $required = LaunchItem::query()->where('required', true)->where('status', '!=', 'not_needed')->count();
        $done = LaunchItem::query()->where('required', true)->where('status', 'done')->count();

        return ['done' => $done, 'required' => $required, 'score' => $required === 0 ? 100 : (int) floor(100 * $done / $required), 'ready' => $done === $required];
    }
}
