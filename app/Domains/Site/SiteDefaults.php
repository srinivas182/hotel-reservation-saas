<?php

declare(strict_types=1);

namespace App\Domains\Site;

/**
 * Starting website content (French and English). The platform edits it in
 * Platform → Website; these texts are only the first version.
 */
final class SiteDefaults
{
    /**
     * @return array<string, array{title: array<string, string>, description: array<string, string>, body: array<string, string>}>
     */
    public static function pages(): array
    {
        return [
            'home' => [
                'title' => ['fr' => 'Votre hôtel répond sur WhatsApp, jour et nuit', 'en' => 'Your hotel answers on WhatsApp, day and night'],
                'description' => ['fr' => 'Un assistant WhatsApp qui renseigne vos clients, prend les demandes de réservation et vous laisse décider. Réservations, restaurant, événements et facturation au même endroit.', 'en' => 'A WhatsApp assistant that answers your guests, takes booking requests and lets you decide. Bookings, restaurant, events and billing in one place.'],
                'body' => [
                    'fr' => <<<'MD'
## Ce que vos clients vivent
Ils écrivent sur WhatsApp, en français ou en anglais, même à 2 h du matin. L'assistant répond avec **vos** informations — prix, disponibilités, petit-déjeuner, parking — et prépare la demande de réservation. **Rien n'est confirmé sans votre équipe.**

## Ce que votre équipe gagne
- **Moins d'appels répétés** : les questions courantes ont déjà une réponse.
- **Tout au même endroit** : réservations, arrivées et départs, note de chambre, restaurant et bar, événements.
- **Vous gardez la main** : reprenez n'importe quelle conversation, en un geste.

## Pensé pour l'Afrique de l'Ouest
Prix en FCFA et dans votre monnaie, paiement par mobile money, TVA de votre pays, interface en français, données de chaque hôtel séparées et chiffrées.
MD,
                    'en' => <<<'MD'
## What your guests get
They write on WhatsApp, in French or English, even at 2 am. The assistant answers with **your** information — prices, availability, breakfast, parking — and prepares the booking request. **Nothing is confirmed without your team.**

## What your team gets
- **Fewer repeated calls**: common questions are already answered.
- **Everything in one place**: bookings, arrivals and departures, room bills, restaurant and bar, events.
- **You stay in control**: take over any conversation in one tap.

## Built for West Africa
Prices in FCFA and your currency, mobile money payments, your country's VAT, a French-first interface, and each hotel's data kept separate and encrypted.
MD,
                ],
            ],
            'features' => [
                'title' => ['fr' => 'Fonctionnalités', 'en' => 'Features'],
                'description' => ['fr' => 'Assistant WhatsApp, réservations, réception, restaurant et bar, événements, facturation.', 'en' => 'WhatsApp assistant, bookings, front desk, restaurant and bar, events, billing.'],
                'body' => [
                    'fr' => <<<'MD'
## Assistant WhatsApp
Répond à partir de votre dossier d'informations, dans la langue du client, comprend les messages vocaux, prépare les réservations et vous les soumet. Mode supervisé pour démarrer : vous validez chaque réponse.

## Réservations et réception
Disponibilités et tarifs par catégorie, réservations téléphone, comptoir et WhatsApp, arrivées et départs du jour, attribution des chambres, note de chambre et encaissements.

## Restaurant et bar
Réservations de tables, menu en ligne avec QR code, commandes depuis la chambre sur WhatsApp, tableau des commandes en cuisine, ajout à la note de chambre.

## Événements
Salles et créneaux, devis acceptés en ligne, acomptes, fiche événement, chambres réservées pour les participants.

## Gestion
Rôles et droits par employé, double authentification, journal d'audit, exports, sauvegardes quotidiennes, demandes des clients sur leurs données.
MD,
                    'en' => <<<'MD'
## WhatsApp assistant
Answers from your information pack, in the guest's language, understands voice notes, prepares bookings and submits them to you. Supervised mode to start: you approve each reply.

## Bookings and front desk
Availability and rates by category, phone, walk-in and WhatsApp bookings, the day's arrivals and departures, room assignment, room bills and payments.

## Restaurant and bar
Table bookings, an online menu with a QR code, room orders on WhatsApp, a kitchen order board, charges to the room bill.

## Events
Spaces and time slots, quotes accepted online, deposits, the event sheet, rooms held for attendees.

## Management
Roles and permissions per employee, two-step login, audit log, exports, daily backups, guest data requests.
MD,
                ],
            ],
            'countries' => [
                'title' => ['fr' => 'Pays servis', 'en' => 'Countries we serve'],
                'description' => ['fr' => 'Disponible en Afrique de l’Ouest et dans d’autres pays d’Afrique, avec la monnaie et la TVA de chaque pays.', 'en' => 'Available across West Africa and other African countries, with each country’s currency and VAT.'],
                'body' => [
                    'fr' => 'Chaque hôtel démarre avec la monnaie, le fuseau horaire, la langue et la TVA de son pays. Les factures sont émises par notre partenaire local.',
                    'en' => "Each hotel starts with its country's currency, time zone, language and VAT. Invoices are issued by our local partner.",
                ],
            ],
            'faq' => [
                'title' => ['fr' => 'Questions fréquentes', 'en' => 'Frequently asked questions'],
                'description' => ['fr' => 'WhatsApp, assistant, prix, paiement, données.', 'en' => 'WhatsApp, the assistant, prices, payment, data.'],
                'body' => [
                    'fr' => <<<'MD'
### Faut-il un nouveau numéro WhatsApp ?
Non : vous connectez le numéro WhatsApp Business de l'hôtel. Les frais de messagerie de Meta vous sont facturés directement par Meta.

### L'assistant peut-il se tromper ?
Il répond uniquement avec les informations que vous publiez et ne confirme jamais une réservation seul. Au démarrage, vous validez chaque réponse.

### Combien de temps pour démarrer ?
Environ une heure pour la mise en place guidée (chambres, prix, WhatsApp, dossier d'informations), puis 14 jours d'essai gratuit.

### Comment payer ?
Mobile money, carte ou virement, en FCFA ou dans votre monnaie. Mensuel ou annuel.

### Mes données sont-elles protégées ?
Chaque hôtel est séparé, les informations sensibles sont chiffrées, sauvegardées chaque jour, et vous pouvez tout exporter.
MD,
                    'en' => <<<'MD'
### Do I need a new WhatsApp number?
No: you connect the hotel's WhatsApp Business number. Meta's messaging fees are billed to you directly by Meta.

### Can the assistant get things wrong?
It only answers with the information you publish and never confirms a booking on its own. At the start, you approve each reply.

### How long does it take to start?
About an hour of guided setup (rooms, prices, WhatsApp, information pack), then a 14-day free trial.

### How do I pay?
Mobile money, card or transfer, in FCFA or your currency. Monthly or yearly.

### Is my data protected?
Each hotel is separate, sensitive information is encrypted, backed up daily, and you can export everything.
MD,
                ],
            ],
        ];
    }
}
