<?php

declare(strict_types=1);

namespace App\Domains\Help;

/**
 * The first help articles (French and English), written from the launch
 * guides, and which screens each one helps with. Edited in Platform → Help.
 */
final class HelpDefaults
{
    /**
     * @return list<array{slug: string, category: string, screens: list<string>, title: array{fr: string, en: string}, body: array{fr: string, en: string}}>
     */
    public static function articles(): array
    {
        return [
            ['slug' => 'demarrer', 'category' => 'start', 'screens' => ['hotel/setup', 'hotel/today', 'dashboard'],
                'title' => ['fr' => 'Bien démarrer', 'en' => 'Getting started'],
                'body' => [
                    'fr' => "Suivez la page **Mise en place** : informations de l'hôtel, chambres, prix, taxes, horaires de réception, équipe, WhatsApp, dossier d'informations, essai de l'assistant, puis demande de mise en service.\n\n- Chaque étape se coche toute seule quand c'est fait.\n- Vous pouvez passer une étape et y revenir.\n- La page **Aujourd'hui** montre les arrivées, départs et clients présents.",
                    'en' => "Follow the **Setup** page: hotel details, rooms, prices, taxes, reception hours, team, WhatsApp, information pack, trying the assistant, then asking to go live.\n\n- Each step ticks itself when done.\n- You can skip a step and come back.\n- The **Today** page shows arrivals, departures and guests in the house.",
                ]],
            ['slug' => 'connecter-whatsapp', 'category' => 'whatsapp', 'screens' => ['hotel/whatsapp', 'hotel/whatsapp-templates'],
                'title' => ['fr' => 'Connecter le numéro WhatsApp de l’hôtel', 'en' => 'Connecting the hotel’s WhatsApp number'],
                'body' => [
                    'fr' => "1. Ouvrez **WhatsApp** dans le menu et cliquez sur **Connecter**.\n2. Connectez-vous à Facebook avec le compte du propriétaire et choisissez (ou créez) le compte WhatsApp Business de l'hôtel.\n3. Saisissez le code reçu par SMS sur le numéro.\n\nLes **modèles de messages** (confirmations, rappels) doivent être approuvés par Meta : cela prend de quelques minutes à 24 h.\n\n**Problème fréquent :** le numéro est déjà utilisé dans l'application WhatsApp du téléphone. Il faut d'abord le retirer de l'application, ou utiliser la coexistence WhatsApp Business.",
                    'en' => "1. Open **WhatsApp** in the menu and click **Connect**.\n2. Log in to Facebook with the owner's account and choose (or create) the hotel's WhatsApp Business account.\n3. Enter the code sent by SMS to the number.\n\n**Message templates** (confirmations, reminders) must be approved by Meta: this takes from a few minutes to 24 hours.\n\n**Common problem:** the number is still used in the WhatsApp app on a phone. Remove it from the app first, or use WhatsApp Business coexistence.",
                ]],
            ['slug' => 'dossier-informations', 'category' => 'assistant', 'screens' => ['hotel/assistant-knowledge', 'hotel/assistant', 'hotel/assistant-practice', 'hotel/go-live'],
                'title' => ['fr' => 'Le dossier d’informations et l’assistant', 'en' => 'The information pack and the assistant'],
                'body' => [
                    'fr' => "L'assistant ne répond qu'avec ce que vous publiez dans le **dossier d'informations** : petit-déjeuner, parking, paiement, annulation, accès…\n\n- Répondez au questionnaire de la mise en place : l'assistant rédige un brouillon en français et en anglais.\n- Relisez, corrigez, puis **Publiez**.\n- Essayez l'assistant dans **Essai** comme si vous étiez un client.\n\n**Modes :** *Supervisé* (vous validez chaque réponse) pour démarrer, puis *Automatique* quand vous êtes en confiance. Il ne confirme jamais une réservation seul.",
                    'en' => "The assistant only answers with what you publish in the **information pack**: breakfast, parking, payment, cancellation, directions…\n\n- Answer the setup questionnaire: the assistant drafts it in French and English.\n- Read, correct, then **Publish**.\n- Try the assistant in **Practice** as if you were a guest.\n\n**Modes:** *Supervised* (you approve each reply) to start, then *Automatic* once you trust it. It never confirms a booking on its own.",
                ]],
            ['slug' => 'messages', 'category' => 'whatsapp', 'screens' => ['hotel/inbox'],
                'title' => ['fr' => 'Répondre aux clients dans Messages', 'en' => 'Answering guests in Messages'],
                'body' => [
                    'fr' => "- **À traiter** : conversations que l'assistant vous a transmises. La raison est affichée en haut.\n- **Reprendre** une conversation met l'assistant en pause pour ce client ; **Rendre la main** le relance.\n- Vous pouvez répondre librement pendant **24 h** après le dernier message du client ; ensuite, seuls les modèles approuvés sont possibles.\n- **Notes internes** : visibles par l'équipe seulement.",
                    'en' => "- **To handle**: conversations the assistant passed to you. The reason is shown at the top.\n- **Take over** pauses the assistant for that guest; **Hand back** restarts it.\n- You can reply freely for **24 hours** after the guest's last message; after that, only approved templates.\n- **Internal notes**: visible to the team only.",
                ]],
            ['slug' => 'reservations-reception', 'category' => 'bookings', 'screens' => ['hotel/bookings/index', 'hotel/bookings/create', 'hotel/bookings/show', 'hotel/calendar'],
                'title' => ['fr' => 'Réservations, arrivées et départs', 'en' => 'Bookings, arrivals and departures'],
                'body' => [
                    'fr' => "- **Nouvelle réservation** : catégorie, dates, client, prix proposé automatiquement.\n- Les demandes WhatsApp arrivent dans **Demandes de réservation** : acceptez ou refusez, le client est prévenu.\n- À l'arrivée : **Attribuer une chambre**, puis **Arrivée**. Au départ : réglez la **note de chambre**, puis **Départ**.\n- **Disponibilités** montre le planning par catégorie.",
                    'en' => "- **New booking**: category, dates, guest, price suggested automatically.\n- WhatsApp requests arrive in **Booking requests**: accept or decline, the guest is told.\n- On arrival: **Assign a room**, then **Check in**. On departure: settle the **room bill**, then **Check out**.\n- **Availability** shows the plan by category.",
                ]],
            ['slug' => 'kit-whatsapp', 'category' => 'bookings', 'screens' => ['hotel/whatsapp-kit'],
                'title' => ['fr' => 'Le kit WhatsApp : affiches, bouton, liens', 'en' => 'The WhatsApp kit: posters, button, links'],
                'body' => [
                    'fr' => "- **Affiches** : téléchargez l'affiche A4 (mur de la réception, chambres) ou le chevalet A5 (tables du restaurant, à plier en deux). Le client scanne le QR code : WhatsApp s'ouvre avec son message déjà écrit.\n- **Bouton du site** : collez une ligne de code avant </body> ; il affiche « En ligne » pendant vos heures.\n- **Google et réseaux** : copiez le lien dans votre fiche Google (Contact → WhatsApp) et le texte dans la bio Facebook ou Instagram.\n- **D'où viennent les clients** : chaque affiche ou lien ajoute un petit code (#R1) au message ; il est retiré et la conversation est marquée. Vous voyez les ouvertures, conversations et réservations de chaque source, et le résumé du lundi.\n- Ajoutez une source par endroit (« Affiche des chambres ») pour savoir laquelle marche le mieux.",
                    'en' => "- **Posters**: download the A4 poster (reception wall, rooms) or the A5 table tent (restaurant tables, folded in two). The guest scans the QR code: WhatsApp opens with their message ready.\n- **Website button**: paste one line of code before </body>; it shows \"Online\" during your hours.\n- **Google and social pages**: copy the link into your Google profile (Contact → WhatsApp) and the text into your Facebook or Instagram bio.\n- **Where guests come from**: each poster or link adds a small code (#R1) to the message; it is removed and the conversation is tagged. You see the opens, chats and bookings of each source, and Monday's summary.\n- Add a source per place (\"Poster in the rooms\") to see which works best.",
                ]],
            ['slug' => 'carte-arrivee', 'category' => 'bookings', 'screens' => ['hotel/arrival-settings'],
                'title' => ['fr' => 'La carte d’arrivée, la veille', 'en' => 'The arrival card, the day before'],
                'body' => [
                    'fr' => "- **La veille**, chaque client confirmé reçoit sur WhatsApp sa réservation, ce qui reste à payer (avec le lien de paiement), et trois boutons : **Je confirme**, **Je ne viens plus**, **J'ai une question**.\n- Ensuite viennent le **plan** (épingle Google Maps) et vos **indications** (« en face de la Grande Mosquée, portail bleu »), puis le choix de l'**heure d'arrivée**.\n- Sur **Aujourd'hui** : ✓ confirmé, l'heure prévue et les demandes (navette, arrivée tôt, lit en plus). Le soir à 18 h, ceux qui n'ont pas répondu sont signalés, avec un bouton pour les appeler.\n- **Je ne viens plus** : vous êtes prévenu ; annulez la réservation depuis le tableau de bord, la règle de l'acompte s'applique.\n- Réglez ici les indications, le conseil transport, l'heure de fermeture de la réception et les demandes proposées.",
                    'en' => "- **The day before**, each confirmed guest gets their booking on WhatsApp, what is left to pay (with the payment link), and three buttons: **I confirm**, **Not coming**, **I have a question**.\n- Then come the **map pin** and your **directions** (\"opposite the Grande Mosquée, blue gate\"), then the **arrival time** choice.\n- On **Today**: ✓ confirmed, the expected time and requests (pickup, early check-in, extra bed). At 18:00 the day before, those who did not answer are flagged, with a button to call them.\n- **Not coming**: you are alerted; cancel the booking from the dashboard, the deposit rule applies.\n- Set the directions, the transport tip, when reception closes and the requests offered here.",
                ]],
            ['slug' => 'acomptes-mobile-money', 'category' => 'bookings', 'screens' => ['hotel/deposits'],
                'title' => ['fr' => 'Acomptes par mobile money', 'en' => 'Deposits by mobile money'],
                'body' => [
                    'fr' => "- **Votre compte** : entrez les clés de votre compte CinetPay (Wave, Orange Money, MTN, Moov). Le client paie l'hôtel directement ; l'argent ne passe jamais par nous.\n- **Le lien** part avec la demande d'acompte sur WhatsApp. Le client choisit l'acompte ou le séjour entier ; dès que le paiement est vérifié, la réservation est confirmée et il reçoit son reçu.\n- **Rappel** 2 heures avant la fin du blocage des chambres.\n- **Capture d'écran** : si un client envoie la preuve d'un transfert, vous recevez une carte « Reçu / Pas reçu ». Vérifiez votre compte avant de toucher Reçu.\n- **Annulation** : selon votre règle (par défaut, remboursé jusqu'à 2 jours avant l'arrivée), l'acompte apparaît dans « Remboursements à faire ».\n- **Export** : le fichier du mois pour votre comptable.",
                    'en' => "- **Your account**: enter the keys of your CinetPay account (Wave, Orange Money, MTN, Moov). Guests pay the hotel directly; the money never passes through us.\n- **The link** goes with the deposit request on WhatsApp. The guest pays the deposit or the whole stay; once the payment is checked, the booking is confirmed and they get a receipt.\n- **Reminder** 2 hours before the rooms are released.\n- **Screenshot**: if a guest sends proof of a transfer, you get a Received / Not received card. Check your account before tapping Received.\n- **Cancellation**: under your rule (by default, refunded up to 2 days before arrival), the deposit shows in Refunds to make.\n- **Export**: the month's file for your accountant.",
                ]],
            ['slug' => 'chambres-tarifs', 'category' => 'setup', 'screens' => ['hotel/rooms', 'hotel/rates', 'hotel/coupons', 'hotel/taxes', 'hotel/settings'],
                'title' => ['fr' => 'Chambres, tarifs et taxes', 'en' => 'Rooms, rates and taxes'],
                'body' => [
                    'fr' => "- **Chambres** : catégories (capacité, description, photos) et numéros de chambre.\n- **Tarifs** : prix de base par nuit, petit-déjeuner, prix par période (haute saison, week-end).\n- **Taxes** : TVA et taxe de séjour, incluses ou non dans les prix.\n- **Réglages** : adresse, horaires d'arrivée et de départ, horaires de réception.",
                    'en' => "- **Rooms**: categories (capacity, description, photos) and room numbers.\n- **Rates**: base price per night, breakfast, seasonal prices (high season, weekends).\n- **Taxes**: VAT and tourist tax, included in prices or not.\n- **Settings**: address, check-in and check-out times, reception hours.",
                ]],
            ['slug' => 'restaurant-bar', 'category' => 'restaurant', 'screens' => ['hotel/restaurant', 'hotel/restaurant-menu', 'hotel/restaurant-orders', 'hotel/restaurant-outlets'],
                'title' => ['fr' => 'Restaurant et bar', 'en' => 'Restaurant and bar'],
                'body' => [
                    'fr' => "- **Points de vente** : restaurant, bar, terrasse, service en chambre, avec leurs horaires.\n- **Menu** : sections, plats, prix, photos ; le lien et le **QR code** du menu sont à imprimer pour les tables.\n- **Commandes** : les commandes WhatsApp des clients arrivent sur le tableau ; *Acceptée*, *Prête*, *Livrée* — le client est prévenu à chaque étape et la commande va sur la note de chambre.",
                    'en' => "- **Outlets**: restaurant, bar, terrace, room service, with their hours.\n- **Menu**: sections, dishes, prices, photos; print the menu link and **QR code** for the tables.\n- **Orders**: guests' WhatsApp orders arrive on the board; *Accepted*, *Ready*, *Delivered* — the guest is told at each step and the order goes on the room bill.",
                ]],
            ['slug' => 'evenements', 'category' => 'events', 'screens' => ['hotel/events', 'hotel/events-setup', 'hotel/events/show'],
                'title' => ['fr' => 'Événements et devis', 'en' => 'Events and quotes'],
                'body' => [
                    'fr' => "- **Réglages des événements** : salles, capacités par disposition, créneaux, prix, forfaits.\n- Une demande devient une **option** (la salle est bloquée jusqu'à une date), puis un **devis** que le client accepte en ligne.\n- La confirmation demande un devis accepté et l'**acompte** encaissé.\n- La **fiche événement** s'imprime pour l'équipe.",
                    'en' => "- **Event setup**: spaces, capacity per layout, time slots, prices, packages.\n- An enquiry becomes an **option** (the space is held until a date), then a **quote** the client accepts online.\n- Confirmation needs an accepted quote and the **deposit** received.\n- The **event sheet** prints for the team.",
                ]],
            ['slug' => 'abonnement-paiement', 'category' => 'billing', 'screens' => ['hotel/billing', 'hotel/usage'],
                'title' => ['fr' => 'Abonnement, crédits et paiement', 'en' => 'Subscription, credits and payment'],
                'body' => [
                    'fr' => "- **Abonnement** montre votre formule, la période, les crédits IA et e-mail restants et vos factures.\n- Payez en ligne (mobile money ou carte) ou par virement avec les coordonnées de la facture.\n- Quand les crédits IA sont épuisés, l'assistant transmet les clients à votre équipe : achetez un pack pour reprendre aussitôt.\n- Les frais WhatsApp de Meta sont facturés par Meta directement.",
                    'en' => "- **Billing** shows your plan, period, AI and email credits left, and invoices.\n- Pay online (mobile money or card) or by transfer using the invoice details.\n- When AI credits run out, the assistant passes guests to your team: buy a pack to resume at once.\n- Meta's WhatsApp fees are billed by Meta directly.",
                ]],
            ['slug' => 'equipe-securite', 'category' => 'team', 'screens' => ['staff/index', 'staff/roles', 'hotel/my-alerts', 'hotel/workspace-settings', 'hotel/audit', 'settings/security', 'settings/profile', 'hotel/support-sessions'],
                'title' => ['fr' => 'Équipe, rôles et sécurité', 'en' => 'Team, roles and security'],
                'body' => [
                    'fr' => "- **Personnel** : invitez vos employés et donnez-leur un rôle (Réception, Restaurant et bar, Événements, Gérant…).\n- La **double authentification** est obligatoire pour le propriétaire.\n- **Mes alertes** : choisissez les alertes reçues par e-mail ou WhatsApp.\n- **Journal** : qui a fait quoi. **Sessions d'assistance** : quand notre équipe vous aide dans votre compte.",
                    'en' => "- **Staff**: invite your employees and give them a role (Reception, Restaurant & Bar, Events, Manager…).\n- **Two-step login** is required for the owner.\n- **My alerts**: choose which alerts you receive by email or WhatsApp.\n- **Audit log**: who did what. **Support sessions**: when our team helps you inside your account.",
                ]],
            ['slug' => 'donnees-clients', 'category' => 'team', 'screens' => ['hotel/privacy', 'account/hotels'],
                'title' => ['fr' => 'Demandes des clients sur leurs données', 'en' => 'Guest requests about their data'],
                'body' => [
                    'fr' => "Un client peut demander à voir ou supprimer ses données.\n\n- **Demandes sur les données clients** : cherchez le client par nom, téléphone ou e-mail.\n- **Télécharger les données** : un fichier à lui envoyer.\n- **Anonymiser** : son nom, ses coordonnées et ses messages sont supprimés ; les réservations et montants restent pour votre comptabilité.\n\n**Mes hôtels** permet aussi d'exporter toutes les données de l'hôtel.",
                    'en' => "A guest can ask to see or delete their data.\n\n- **Guest data requests**: find the guest by name, phone or email.\n- **Download data**: a file to send them.\n- **Anonymise**: their name, contact details and messages are removed; bookings and amounts stay for your accounts.\n\n**My hotels** also lets you export all the hotel's data.",
                ]],
        ];
    }
}
