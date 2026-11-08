<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Help article for the new support pages (S17.2b).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('help_articles')->insertOrIgnore([
            'slug' => 'contacter-support', 'category' => 'team', 'published' => true, 'sort_order' => 20,
            'screens' => json_encode(['hotel/support', 'hotel/support-ticket']),
            'title' => json_encode(['fr' => 'Contacter le support', 'en' => 'Contacting support']),
            'body' => json_encode([
                'fr' => "- **Contacter le support** dans le menu, ou **Toujours bloqué ?** dans le panneau d'aide « ? ».\n- Écrivez le sujet : des articles qui répondent souvent à la question s'affichent tout de suite.\n- Ajoutez une capture d'écran et entourez le problème (cadre, flèche, crayon).\n- Nous répondons dans l'application et par e-mail (et sur WhatsApp si vous le choisissez). Le délai de première réponse dépend de votre formule.\n- Quand la demande est résolue, dites-nous en un clic si nous vous avons aidé.",
                'en' => "- **Contact support** in the menu, or **Still stuck?** in the “?” help panel.\n- Type the subject: articles that often answer the question appear straight away.\n- Add a screenshot and mark the problem (box, arrow, pen).\n- We answer in the app and by email (and on WhatsApp if you choose). The first-answer time depends on your plan.\n- When the request is solved, tell us in one tap whether we helped.",
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('help_articles')->where('slug', 'contacter-support')->delete();
    }
};
