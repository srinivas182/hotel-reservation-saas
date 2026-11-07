<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

/**
 * Starting legal texts (version 1). Drafts to be reviewed by CNH's legal
 * adviser; the platform publishes new versions from Platform → Sign-ups.
 */
final class LegalDefaults
{
    /**
     * @return array<string, array{fr: string, en: string}>
     */
    public static function all(): array
    {
        return [
            'terms' => [
                'fr' => <<<'MD'
# Conditions générales d'utilisation

*Version 1 — projet à faire valider par le conseil juridique.*

**1. Objet.** Les présentes conditions régissent l'utilisation par l'hôtel (« le Client ») de la plateforme de réservation et d'assistant WhatsApp exploitée par CNH Global Tech en partenariat avec Mayura Consultancy Services (« l'Éditeur »).

**2. Compte.** Le Client fournit des informations exactes, protège ses accès (double authentification obligatoire pour le propriétaire) et reste responsable des actions de ses comptes d'équipe.

**3. Abonnement et paiement.** L'accès est fourni selon la formule choisie, après une période d'essai. Les factures sont émises par CNH Global Tech et payables à l'échéance indiquée. En cas d'impayé de plus de 14 jours, le service passe en mode réduit (assistant en pause, réglages en lecture seule) sans perte de données.

**4. Crédits.** Les crédits IA et e-mail inclus sont valables pour la période en cours ; les packs achetés sont valables 12 mois. Ils ne sont pas remboursables.

**5. WhatsApp.** Le Client connecte son propre numéro WhatsApp Business et respecte les règles de Meta. Les frais de messagerie de Meta sont facturés directement au Client par Meta.

**6. Assistant IA.** L'assistant répond aux clients à partir des informations publiées par le Client. Le Client vérifie ces informations, peut reprendre une conversation à tout moment et reste responsable des réservations qu'il confirme.

**7. Données.** Le Client est responsable du traitement des données de ses clients ; l'Éditeur agit comme sous-traitant selon l'accord de traitement des données accepté à l'inscription.

**8. Disponibilité.** L'Éditeur met en œuvre des moyens raisonnables pour assurer la disponibilité du service et des sauvegardes quotidiennes, sans garantie d'absence d'interruption.

**9. Responsabilité.** La responsabilité de l'Éditeur est limitée au montant payé par le Client sur les 12 derniers mois.

**10. Résiliation.** Le Client peut résilier à la fin de toute période. Ses données peuvent être exportées pendant 90 jours, puis sont supprimées.

**11. Droit applicable.** Droit ivoirien ; tribunaux d'Abidjan.
MD,
                'en' => <<<'MD'
# Terms of service

*Version 1 — draft for review by the legal adviser.*

**1. Purpose.** These terms govern the hotel's ("the Customer") use of the booking and WhatsApp assistant platform operated by CNH Global Tech in partnership with Mayura Consultancy Services ("the Provider").

**2. Account.** The Customer gives accurate information, protects its access (two-step login required for the owner) and is responsible for actions taken by its staff accounts.

**3. Subscription and payment.** Access is provided under the chosen plan after a trial. Invoices are issued by CNH Global Tech and are payable by the due date. If an invoice is more than 14 days overdue, the service moves to reduced mode (assistant paused, settings read-only) without loss of data.

**4. Credits.** Included AI and email credits are valid for the current period; packs bought are valid for 12 months. They are not refundable.

**5. WhatsApp.** The Customer connects its own WhatsApp Business number and follows Meta's rules. Meta's messaging fees are billed to the Customer directly by Meta.

**6. AI assistant.** The assistant answers guests from the information the Customer publishes. The Customer checks that information, can take over any conversation, and remains responsible for the bookings it confirms.

**7. Data.** The Customer controls its guests' data; the Provider acts as processor under the data processing agreement accepted at sign-up.

**8. Availability.** The Provider uses reasonable efforts to keep the service available and backed up daily, without guaranteeing it will never be interrupted.

**9. Liability.** The Provider's liability is limited to the amount the Customer paid in the last 12 months.

**10. Termination.** The Customer can end the subscription at the end of any period. Its data can be exported for 90 days, then it is deleted.

**11. Governing law.** Law of Côte d'Ivoire; courts of Abidjan.
MD,
            ],
            'privacy' => [
                'fr' => <<<'MD'
# Politique de confidentialité (utilisateurs de la plateforme)

*Version 1 — projet à faire valider par le conseil juridique.*

Nous traitons les données des utilisateurs de la plateforme (propriétaires et équipes des hôtels) : nom, e-mail, téléphone, journal d'activité et de connexion. Finalités : fournir le service, la sécurité, la facturation et le support. Ces données sont hébergées de façon sécurisée, chiffrées pour les informations sensibles, et conservées pendant la durée du contrat puis 12 mois au plus (sauf obligation légale). Vous pouvez demander l'accès, la rectification ou la suppression de vos données en écrivant à l'adresse indiquée sur votre facture. Les données des clients des hôtels sont traitées pour le compte des hôtels, selon l'accord de traitement des données.
MD,
                'en' => <<<'MD'
# Privacy notice (platform users)

*Version 1 — draft for review by the legal adviser.*

We process the data of platform users (hotel owners and staff): name, email, phone, activity and login records. Purposes: providing the service, security, billing and support. This data is hosted securely, sensitive values are encrypted, and it is kept for the length of the contract then at most 12 months (unless the law requires longer). You can ask for access, correction or deletion by writing to the address on your invoice. Hotel guests' data is processed on behalf of the hotels, under the data processing agreement.
MD,
            ],
            'dpa' => [
                'fr' => <<<'MD'
# Accord de traitement des données

*Version 1 — projet à faire valider par le conseil juridique.*

L'hôtel (responsable du traitement) confie à CNH Global Tech et à Mayura Consultancy Services (sous-traitants) le traitement des données de ses clients pour : répondre aux messages WhatsApp, gérer les réservations, envoyer les messages liés au séjour et assister l'équipe. Le sous-traitant traite les données uniquement sur instruction de l'hôtel ; assure leur confidentialité et leur sécurité (chiffrement, sauvegardes chiffrées, double authentification, journal d'audit, séparation entre hôtels) ; aide l'hôtel à répondre aux demandes des personnes ; notifie toute violation sous 48 heures ; restitue puis supprime les données à la fin du contrat. Sous-traitants ultérieurs : Meta (WhatsApp), fournisseurs d'intelligence artificielle, hébergeur, fournisseurs d'e-mail et de paiement. Les transferts hors du pays respectent la loi applicable (Côte d'Ivoire : loi n° 2013-450 et ARTCI).
MD,
                'en' => <<<'MD'
# Data processing agreement

*Version 1 — draft for review by the legal adviser.*

The hotel (controller) entrusts CNH Global Tech and Mayura Consultancy Services (processors) with processing its guests' data to: answer WhatsApp messages, manage bookings, send stay-related messages and assist the team. The processor processes data only on the hotel's instructions; keeps it confidential and secure (encryption, encrypted backups, two-step login, audit log, separation between hotels); helps the hotel answer requests from individuals; reports any breach within 48 hours; returns then deletes the data at the end of the contract. Sub-processors: Meta (WhatsApp), artificial intelligence providers, the host, email and payment providers. Transfers abroad follow the applicable law (Côte d'Ivoire: Law No. 2013-450 and ARTCI).
MD,
            ],
        ];
    }
}
