<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

/**
 * The platform's standard legal texts (0.37.7: version 2, full texts).
 *
 * Words in double braces are filled in when a page is shown, from the
 * issuing company (Platform → Billing) and the brand settings: see
 * LegalPlaceholders. The platform publishes new versions from
 * Platform → Sign-ups → Legal texts; a new version is accepted by each
 * hotel at its next login.
 */
final class LegalDefaults
{
    /**
     * @return array<string, array{fr: string, en: string}>
     */
    public static function all(): array
    {
        return [
            'terms' => ['fr' => self::TERMS_FR, 'en' => self::TERMS_EN],
            'privacy' => ['fr' => self::PRIVACY_FR, 'en' => self::PRIVACY_EN],
            'dpa' => ['fr' => self::DPA_FR, 'en' => self::DPA_EN],
        ];
    }

    private const TERMS_EN = <<<'MD'
# Terms of service

These terms form the contract between **{{company}}** ("we", "us") and the hotel, guest house or other accommodation business that creates an account on **{{brand}}** ("you", "the Customer"). By creating an account or using the platform, you accept these terms on behalf of your business.

## 1. The service

{{brand}} is an online platform for accommodation businesses. It provides room and booking management, a front desk, a WhatsApp assistant that answers guests and prepares booking requests, guest payments through the Customer's own payment accounts, and, depending on the plan, restaurant, room orders and events. The platform is operated by {{company}}; its technology is provided by MCSpoorthi IT Solutions Private Limited (Mayura Consultancy Services), India.

## 2. Your account

- You give accurate information about your business and keep it up to date.
- You keep your access details secret. Two-step login is required for the owner account.
- You are responsible for everything done with the staff accounts you create, and for removing access of people who leave.
- You must be legally able to run your accommodation business and to sign this contract.

## 3. Trial, plans and payment

- A new account may start with a free trial. At its end, the service continues on the plan you choose.
- Prices are shown on the website and in your account. Invoices are issued by {{company}} and are payable by the due date shown on the invoice.
- If an invoice is more than 14 days overdue, the service moves to reduced mode: the assistant is paused and settings become read-only. Your data is kept. Full service returns once the invoice is paid.
- We may change prices for the next billing period with at least 30 days' notice.

## 4. Credits

AI and email credits included in a plan are valid for the current period. Credit packs bought separately are valid for 12 months. Credits are not refundable and cannot be exchanged for money.

## 5. WhatsApp and Meta

- You connect your own WhatsApp Business number through Meta's official process and accept Meta's WhatsApp Business terms and policies.
- Meta bills its messaging fees directly to your own WhatsApp account. A payment method must be kept on that account, otherwise Meta may stop delivering messages.
- You only send messages to guests who contacted you or agreed to receive them, and you never use the platform for unsolicited marketing.

## 6. The AI assistant

- The assistant answers guests from the information you publish (rooms, rates, policies, information pack) and from live availability.
- You check that this information is correct and keep it up to date. You can review conversations, take over at any time, and set the assistant to ask for staff approval before replying.
- The assistant sends booking requests; you decide which to accept. You remain responsible for the bookings you confirm, your prices and your relations with your guests.
- The assistant is a tool. Its replies can contain mistakes, and it does not replace your own judgement.

## 7. Guest payments

Guests pay deposits and stays into your own payment provider account (for example CinetPay or Stripe) or your bank account. We never hold your guests' money. You are responsible for your provider contract, refunds, chargebacks and the taxes on your sales.

## 8. Your data and your guests' data

- You remain the owner of your data and of your guests' data.
- For your guests' data, you are the controller and we act as processor, under the data processing agreement that forms part of these terms.
- How we handle the personal data of platform users is described in our privacy notice.

## 9. Acceptable use

You must not use the platform to break the law, send spam, collect data without a legal basis, upload malicious content, try to access another hotel's data, overload or reverse-engineer the service, or resell it without our written agreement.

## 10. Availability and support

We take reasonable steps to keep the service available, secure and backed up daily, and we announce planned maintenance in advance when possible. We do not guarantee that the service will never be interrupted. Support hours and response times are published on the website and depend on your plan.

## 11. Changes to the service

We improve the platform continuously. We may change or remove features; if a change removes a significant feature you pay for, we tell you in advance and you may end your subscription.

## 12. Liability

- We are not liable for indirect losses such as loss of profit, bookings or reputation, nor for failures of services we do not control (Meta, internet providers, payment providers).
- Our total liability under this contract is limited to the amounts you paid us in the 12 months before the event that caused the claim.
- Nothing in these terms limits liability that cannot be limited by law.

## 13. Suspension and termination

- You may end your subscription at the end of any billing period from your account or by writing to us.
- We may suspend or end the service if you seriously breach these terms, for example by non-payment or unlawful use, after notice when the situation allows.
- After the end of the contract, you can export your data for 90 days. It is then deleted, except invoices and records we must keep by law.

## 14. Changes to these terms

We may update these terms. The new version is shown in your account and applies once you accept it at your next login. If you do not accept it, you may end your subscription.

## 15. Applicable law and disputes

These terms are governed by the laws of Côte d'Ivoire and the OHADA Uniform Acts. We will first try to settle any dispute amicably. If that fails, the courts of Abidjan have jurisdiction.

## 16. Contact

{{company}} · {{address}} · {{email}}
MD;

    private const TERMS_FR = <<<'MD'
# Conditions générales d'utilisation

Les présentes conditions forment le contrat entre **{{company}}** (« nous ») et l'hôtel, la résidence ou tout autre établissement d'hébergement qui crée un compte sur **{{brand}}** (« vous », « le Client »). En créant un compte ou en utilisant la plateforme, vous acceptez ces conditions au nom de votre entreprise.

## 1. Le service

{{brand}} est une plateforme en ligne pour les établissements d'hébergement. Elle permet de gérer les chambres et les réservations, la réception, un assistant WhatsApp qui répond aux clients et prépare les demandes de réservation, les paiements des clients sur les propres comptes de paiement du Client et, selon la formule, le restaurant, les commandes en chambre et les événements. La plateforme est exploitée par {{company}} ; sa technologie est fournie par MCSpoorthi IT Solutions Private Limited (Mayura Consultancy Services), Inde.

## 2. Votre compte

- Vous fournissez des informations exactes sur votre entreprise et les tenez à jour.
- Vous gardez vos accès secrets. La double authentification est obligatoire pour le compte du propriétaire.
- Vous êtes responsable de tout ce qui est fait avec les comptes d'équipe que vous créez, et de retirer l'accès des personnes qui partent.
- Vous devez être légalement autorisé à exploiter votre établissement et à signer ce contrat.

## 3. Essai, formules et paiement

- Un nouveau compte peut commencer par un essai gratuit. À sa fin, le service continue selon la formule choisie.
- Les prix figurent sur le site et dans votre compte. Les factures sont émises par {{company}} et payables à l'échéance indiquée.
- En cas de facture impayée depuis plus de 14 jours, le service passe en mode réduit : l'assistant est en pause et les réglages sont en lecture seule. Vos données sont conservées. Le service complet reprend dès le paiement.
- Nous pouvons modifier les prix pour la période suivante avec un préavis d'au moins 30 jours.

## 4. Crédits

Les crédits IA et e-mail inclus dans une formule sont valables pour la période en cours. Les packs achetés séparément sont valables 12 mois. Les crédits ne sont ni remboursables ni convertibles en argent.

## 5. WhatsApp et Meta

- Vous connectez votre propre numéro WhatsApp Business par la procédure officielle de Meta et acceptez les conditions et règles de WhatsApp Business.
- Meta facture ses frais de messagerie directement sur votre propre compte WhatsApp. Un moyen de paiement doit y rester enregistré, faute de quoi Meta peut cesser de délivrer les messages.
- Vous n'écrivez qu'aux clients qui vous ont contacté ou ont accepté de recevoir vos messages, et n'utilisez jamais la plateforme pour de la prospection non sollicitée.

## 6. L'assistant IA

- L'assistant répond aux clients à partir des informations que vous publiez (chambres, tarifs, conditions, dossier d'informations) et des disponibilités en temps réel.
- Vous vérifiez que ces informations sont exactes et à jour. Vous pouvez relire les conversations, reprendre la main à tout moment et demander à l'assistant d'attendre la validation de l'équipe avant de répondre.
- L'assistant envoie des demandes de réservation ; vous décidez de les accepter. Vous restez responsable des réservations que vous confirmez, de vos prix et de vos relations avec vos clients.
- L'assistant est un outil. Ses réponses peuvent contenir des erreurs et ne remplacent pas votre propre jugement.

## 7. Paiements des clients

Les clients paient acomptes et séjours sur votre propre compte de paiement (par exemple CinetPay ou Stripe) ou votre compte bancaire. Nous ne détenons jamais l'argent de vos clients. Vous êtes responsable de votre contrat avec le prestataire, des remboursements, des contestations et des taxes sur vos ventes.

## 8. Vos données et celles de vos clients

- Vous restez propriétaire de vos données et de celles de vos clients.
- Pour les données de vos clients, vous êtes responsable du traitement et nous agissons comme sous-traitant, selon l'accord de traitement des données qui fait partie de ces conditions.
- La façon dont nous traitons les données personnelles des utilisateurs de la plateforme est décrite dans notre politique de confidentialité.

## 9. Usage acceptable

Vous ne devez pas utiliser la plateforme pour enfreindre la loi, envoyer du spam, collecter des données sans base légale, déposer du contenu malveillant, tenter d'accéder aux données d'un autre hôtel, surcharger ou décompiler le service, ni le revendre sans notre accord écrit.

## 10. Disponibilité et support

Nous mettons en œuvre des moyens raisonnables pour que le service soit disponible, sécurisé et sauvegardé chaque jour, et annonçons les maintenances prévues lorsque c'est possible. Nous ne garantissons pas l'absence totale d'interruption. Les horaires et délais du support sont publiés sur le site et dépendent de votre formule.

## 11. Évolution du service

Nous améliorons la plateforme en continu et pouvons modifier ou retirer des fonctionnalités ; si une modification retire une fonctionnalité importante que vous payez, nous vous prévenons à l'avance et vous pouvez résilier.

## 12. Responsabilité

- Nous ne sommes pas responsables des pertes indirectes (manque à gagner, perte de réservations ou d'image), ni des défaillances de services que nous ne maîtrisons pas (Meta, fournisseurs d'accès, prestataires de paiement).
- Notre responsabilité totale au titre du contrat est limitée aux sommes que vous nous avez payées au cours des 12 mois précédant le fait générateur.
- Rien dans ces conditions ne limite une responsabilité qui ne peut l'être selon la loi.

## 13. Suspension et résiliation

- Vous pouvez résilier à la fin de toute période de facturation, depuis votre compte ou en nous écrivant.
- Nous pouvons suspendre ou résilier le service en cas de manquement grave (par exemple impayé ou usage illicite), après mise en demeure lorsque la situation le permet.
- Après la fin du contrat, vous pouvez exporter vos données pendant 90 jours. Elles sont ensuite supprimées, sauf les factures et registres que la loi nous oblige à conserver.

## 14. Modification des conditions

Nous pouvons mettre à jour ces conditions. La nouvelle version s'affiche dans votre compte et s'applique dès que vous l'acceptez à votre prochaine connexion. Si vous ne l'acceptez pas, vous pouvez résilier.

## 15. Droit applicable et litiges

Ces conditions sont régies par le droit ivoirien et les Actes uniformes OHADA. Nous chercherons d'abord une solution amiable. À défaut, les tribunaux d'Abidjan sont compétents.

## 16. Contact

{{company}} · {{address}} · {{email}}
MD;

    private const PRIVACY_EN = <<<'MD'
# Privacy notice

This notice explains how **{{company}}** handles personal data on the **{{brand}}** platform and website: what we collect, why, who we share it with, how long we keep it and how you can exercise your rights.

## 1. Who we are

{{brand}} is operated by {{company}}, {{address}}. Our technology provider, MCSpoorthi IT Solutions Private Limited (Mayura Consultancy Services), India, builds and runs the platform on our behalf. You can reach us at {{email}}.

## 2. Two roles

- **Platform users** (hotel owners and staff) and **website visitors**: we decide how their data is used. We are the **controller**.
- **Hotel guests** who write to a hotel on WhatsApp or book with it: the **hotel** is the controller. We process their data only on the hotel's instructions, as **processor**, under our data processing agreement. Guests should contact the hotel first; we help the hotel answer.

## 3. Data we collect

**Platform users:** name, business email, phone number, role, the hotel you work for, login and activity records (date, IP address, actions), two-step login settings, support requests, and billing details of the business.

**Hotel guests (on behalf of hotels):** WhatsApp name and number, messages, voice notes and their transcription, photos or documents the guest sends, booking details (dates, room, number of guests, special requests), orders and the room bill, payment status and references (never full card numbers), and the guest's language.

**Website visitors:** the details you enter in the demo or contact form (name, hotel, phone, email, message) and technical logs needed to run and secure the website.

## 4. Why we use it

- To provide the service: accounts, bookings, the WhatsApp assistant, payments, messages linked to a stay (contract).
- To secure the platform, prevent fraud and keep audit records (legitimate interest and legal obligations).
- To bill hotels and keep accounting records (contract and legal obligation).
- To answer support requests and demo requests (contract or your request).
- To improve the platform using aggregated statistics that do not identify individuals (legitimate interest).

We do not sell personal data and we do not use it for advertising.

## 5. The AI assistant

Guest messages are processed by an artificial intelligence service to understand the question and write a reply, using only the hotel's published information and live availability. Voice notes are transcribed for the same purpose. We use the AI provider's business API, under which data is **not used to train its models**. Hotels can review every conversation, take over at any time, and guests can always ask to speak to a person.

## 6. WhatsApp

Messages between guests and hotels travel through WhatsApp, a Meta service. Meta processes this data under its own terms and privacy policy. Each hotel connects its own WhatsApp Business number.

## 7. Who we share data with

Only with service providers we need to run the platform, bound by contract to protect the data and use it only for us:

| Provider | Purpose |
| --- | --- |
| Meta Platforms (WhatsApp Business Platform) | Sending and receiving WhatsApp messages |
| OpenAI (and, as a back-up, another AI provider) | Understanding messages, writing replies, transcribing voice notes |
| Brevo | Sending emails |
| The hotel's payment providers (for example CinetPay, Stripe) | Guest payments, paid directly to the hotel |
| Our hosting and backup provider | Running the platform and storing encrypted backups |
| Sentry | Error tracking, with personal data kept to a minimum |
| MCSpoorthi IT Solutions (Mayura Consultancy Services) | Building, running and supporting the platform |

We may also disclose data when the law requires it, or to protect the rights and safety of people or of the platform.

## 8. International transfers

Some providers are located outside Côte d'Ivoire (for example in the European Union, the United States or India). We only use providers that offer appropriate safeguards, such as contractual commitments on data protection, and we transfer only what each one needs.

## 9. How long we keep data

| Data | Kept |
| --- | --- |
| Platform user accounts | For the length of the contract, then at most 12 months |
| Guest data (for hotels) | As long as the hotel keeps it; deleted within 90 days after the hotel's contract ends |
| AI processing logs | 30 days |
| Raw WhatsApp delivery records | 30 days |
| Email sending logs | 90 days |
| Invoices and accounting records | 10 years (accounting law) |
| Demo and contact requests | 24 months after the last contact |

## 10. Security

Data is transmitted over encrypted connections (HTTPS). Sensitive values such as access keys are encrypted in the database, backups are encrypted, owners must use two-step login, access is limited by role, every hotel's data is kept separate, and important actions are recorded in an audit log.

## 11. Your rights

Under Côte d'Ivoire's Law No. 2013-450 on personal data protection, and where applicable other laws such as the European GDPR, you can ask to access your data, correct it, delete it, object to or limit its use, and receive a copy. Write to {{email}}. We answer within 30 days. Guests should first contact the hotel concerned; hotels can export or anonymise a guest's data from the platform. You may also complain to the Autorité de Régulation des Télécommunications/TIC de Côte d'Ivoire (ARTCI).

## 12. Deleting your data

- **Hotel guests:** ask the hotel on WhatsApp to delete your data; the hotel can anonymise it from the platform. You can also write to {{email}} with your WhatsApp number and the hotel's name, and we will forward and follow up the request.
- **Platform users:** ask your hotel owner to remove your account, or write to {{email}} from your account email.
- **Hotels:** end your subscription; your data is deleted 90 days later, after you have had the chance to export it.

## 13. Cookies

The platform uses only the cookies needed to keep you logged in and protect forms (session and security cookies). The website does not use advertising cookies. If anonymous audience statistics are added, they will not identify you.

## 14. Children

The platform is meant for businesses and is not directed at children. Hotels are responsible for the data of guests under 18 staying with an adult.

## 15. Changes to this notice

We may update this notice. The version number and date are shown at the top of the page, and important changes are announced to platform users.

## 16. Contact

{{company}} · {{address}} · {{email}}
MD;

    private const PRIVACY_FR = <<<'MD'
# Politique de confidentialité

Cette politique explique comment **{{company}}** traite les données personnelles sur la plateforme et le site **{{brand}}** : ce que nous collectons, pourquoi, avec qui nous les partageons, combien de temps nous les gardons et comment exercer vos droits.

## 1. Qui sommes-nous

{{brand}} est exploité par {{company}}, {{address}}. Notre prestataire technique, MCSpoorthi IT Solutions Private Limited (Mayura Consultancy Services), Inde, développe et fait fonctionner la plateforme pour notre compte. Vous pouvez nous écrire à {{email}}.

## 2. Deux rôles

- **Utilisateurs de la plateforme** (propriétaires et équipes des hôtels) et **visiteurs du site** : nous décidons de l'usage de leurs données. Nous sommes **responsable du traitement**.
- **Clients des hôtels** qui écrivent à un hôtel sur WhatsApp ou réservent chez lui : l'**hôtel** est responsable du traitement. Nous traitons leurs données uniquement sur ses instructions, comme **sous-traitant**, selon notre accord de traitement des données. Les clients s'adressent d'abord à l'hôtel ; nous l'aidons à répondre.

## 3. Données collectées

**Utilisateurs de la plateforme :** nom, e-mail professionnel, téléphone, rôle, hôtel concerné, journaux de connexion et d'activité (date, adresse IP, actions), réglages de double authentification, demandes de support et coordonnées de facturation de l'entreprise.

**Clients des hôtels (pour le compte des hôtels) :** nom et numéro WhatsApp, messages, notes vocales et leur transcription, photos ou documents envoyés, détails de réservation (dates, chambre, nombre de personnes, demandes particulières), commandes et note de chambre, état et références des paiements (jamais le numéro complet d'une carte), langue du client.

**Visiteurs du site :** les informations saisies dans le formulaire de démonstration ou de contact (nom, hôtel, téléphone, e-mail, message) et les journaux techniques nécessaires au fonctionnement et à la sécurité du site.

## 4. Pourquoi nous les utilisons

- Fournir le service : comptes, réservations, assistant WhatsApp, paiements, messages liés au séjour (contrat).
- Sécuriser la plateforme, prévenir la fraude et tenir un journal d'audit (intérêt légitime et obligations légales).
- Facturer les hôtels et tenir la comptabilité (contrat et obligation légale).
- Répondre aux demandes de support et de démonstration (contrat ou votre demande).
- Améliorer la plateforme grâce à des statistiques agrégées qui n'identifient personne (intérêt légitime).

Nous ne vendons pas de données personnelles et ne les utilisons pas pour de la publicité.

## 5. L'assistant IA

Les messages des clients sont traités par un service d'intelligence artificielle pour comprendre la question et rédiger une réponse, uniquement à partir des informations publiées par l'hôtel et des disponibilités en temps réel. Les notes vocales sont transcrites dans le même but. Nous utilisons l'offre professionnelle (API) du fournisseur d'IA, dans laquelle les données **ne servent pas à entraîner ses modèles**. Les hôtels peuvent relire chaque conversation et reprendre la main à tout moment ; le client peut toujours demander à parler à une personne.

## 6. WhatsApp

Les messages entre clients et hôtels transitent par WhatsApp, un service de Meta, qui traite ces données selon ses propres conditions et sa politique de confidentialité. Chaque hôtel connecte son propre numéro WhatsApp Business.

## 7. Avec qui nous partageons les données

Uniquement avec les prestataires nécessaires au fonctionnement de la plateforme, tenus par contrat de protéger les données et de ne les utiliser que pour nous :

| Prestataire | Finalité |
| --- | --- |
| Meta Platforms (WhatsApp Business Platform) | Envoi et réception des messages WhatsApp |
| OpenAI (et, en secours, un autre fournisseur d'IA) | Compréhension des messages, rédaction des réponses, transcription des notes vocales |
| Brevo | Envoi des e-mails |
| Les prestataires de paiement de l'hôtel (par exemple CinetPay, Stripe) | Paiements des clients, versés directement à l'hôtel |
| Notre hébergeur et prestataire de sauvegarde | Fonctionnement de la plateforme et sauvegardes chiffrées |
| Sentry | Suivi des erreurs, avec un minimum de données personnelles |
| MCSpoorthi IT Solutions (Mayura Consultancy Services) | Développement, exploitation et support de la plateforme |

Nous pouvons aussi communiquer des données lorsque la loi l'exige, ou pour protéger les droits et la sécurité des personnes ou de la plateforme.

## 8. Transferts internationaux

Certains prestataires sont situés hors de Côte d'Ivoire (par exemple dans l'Union européenne, aux États-Unis ou en Inde). Nous ne faisons appel qu'à des prestataires offrant des garanties appropriées, notamment des engagements contractuels de protection des données, et ne transférons que ce dont chacun a besoin.

## 9. Durées de conservation

| Données | Conservation |
| --- | --- |
| Comptes des utilisateurs de la plateforme | Durée du contrat, puis 12 mois au plus |
| Données des clients (pour les hôtels) | Tant que l'hôtel les conserve ; supprimées dans les 90 jours suivant la fin de son contrat |
| Journaux de traitement IA | 30 jours |
| Données brutes de livraison WhatsApp | 30 jours |
| Journaux d'envoi des e-mails | 90 jours |
| Factures et pièces comptables | 10 ans (obligations comptables) |
| Demandes de démonstration et de contact | 24 mois après le dernier contact |

## 10. Sécurité

Les données circulent sur des connexions chiffrées (HTTPS). Les valeurs sensibles comme les clés d'accès sont chiffrées en base, les sauvegardes sont chiffrées, la double authentification est obligatoire pour les propriétaires, les accès sont limités selon les rôles, les données de chaque hôtel sont séparées et les actions importantes sont enregistrées dans un journal d'audit.

## 11. Vos droits

Selon la loi ivoirienne n° 2013-450 relative à la protection des données à caractère personnel et, le cas échéant, d'autres lois comme le RGPD européen, vous pouvez demander l'accès à vos données, leur rectification, leur suppression, vous opposer à leur usage ou le limiter, et en obtenir une copie. Écrivez à {{email}} : nous répondons sous 30 jours. Les clients d'un hôtel s'adressent d'abord à cet hôtel ; l'hôtel peut exporter ou anonymiser les données d'un client depuis la plateforme. Vous pouvez aussi saisir l'Autorité de Régulation des Télécommunications/TIC de Côte d'Ivoire (ARTCI).

## 12. Supprimer vos données

- **Clients des hôtels :** demandez à l'hôtel sur WhatsApp de supprimer vos données ; il peut les anonymiser depuis la plateforme. Vous pouvez aussi écrire à {{email}} en indiquant votre numéro WhatsApp et le nom de l'hôtel : nous transmettons et suivons la demande.
- **Utilisateurs de la plateforme :** demandez au propriétaire de l'hôtel de retirer votre compte, ou écrivez à {{email}} depuis l'adresse de votre compte.
- **Hôtels :** résiliez votre abonnement ; vos données sont supprimées 90 jours plus tard, après la possibilité de les exporter.

## 13. Cookies

La plateforme n'utilise que les cookies nécessaires pour vous garder connecté et protéger les formulaires (cookies de session et de sécurité). Le site n'utilise pas de cookies publicitaires. Si des statistiques d'audience anonymes sont ajoutées, elles ne vous identifieront pas.

## 14. Enfants

La plateforme s'adresse aux entreprises et ne vise pas les enfants. Les hôtels sont responsables des données des clients de moins de 18 ans séjournant avec un adulte.

## 15. Modifications

Nous pouvons mettre à jour cette politique. Le numéro de version et la date figurent en haut de la page ; les changements importants sont annoncés aux utilisateurs de la plateforme.

## 16. Contact

{{company}} · {{address}} · {{email}}
MD;

    private const DPA_EN = <<<'MD'
# Data processing agreement

This agreement is part of the terms of service between the hotel ("the Controller") and **{{company}}** ("the Processor"), operator of **{{brand}}**. It applies to the personal data of the Controller's guests that the Processor handles when providing the service.

## 1. Subject and duration

The Processor processes guest data only to provide the service: answering WhatsApp messages with the assistant, managing bookings, payments and room bills, sending stay-related messages, and supporting the Controller's team. The agreement lasts as long as the terms of service.

## 2. Data and people concerned

Guests and people who contact the Controller: name, WhatsApp number, email, messages and voice notes, booking details and special requests, orders and bills, payment status, language.

## 3. Instructions

The Processor processes the data only on the Controller's documented instructions, which are the terms of service, this agreement and the Controller's settings in the platform. It tells the Controller if an instruction seems unlawful.

## 4. Confidentiality

People authorised to process the data are bound by confidentiality and access it only when needed for their work.

## 5. Security

The Processor keeps appropriate security measures: encrypted connections, encryption of sensitive values and of backups, daily backups, role-based access, two-step login for owners, separation between hotels, audit logs and monitoring.

## 6. Sub-processors

The Controller authorises these sub-processors: MCSpoorthi IT Solutions Private Limited (Mayura Consultancy Services) for development, operation and support; Meta Platforms for WhatsApp; OpenAI and, as a back-up, another AI provider; Brevo for emails; the hosting and backup provider; Sentry for error tracking. The Processor binds each one to equivalent obligations and informs the Controller of any change in advance, so the Controller can object.

## 7. International transfers

Transfers outside Côte d'Ivoire are limited to what each sub-processor needs and are covered by appropriate safeguards.

## 8. Rights of individuals

The platform lets the Controller find, export and anonymise a guest's data. The Processor helps the Controller answer requests and forwards any request it receives directly.

## 9. Personal data breaches

The Processor notifies the Controller without undue delay, and at the latest within 48 hours of becoming aware of a breach, with the information available to help the Controller meet its own obligations.

## 10. Assistance

The Processor helps the Controller with security, impact assessments and dealings with the authority (ARTCI), as far as the information it holds allows.

## 11. End of the service

At the end of the contract, the Controller can export its data for 90 days. The Processor then deletes it, including from backups as they expire, except what the law requires it to keep.

## 12. Audits

The Processor makes available the information needed to show compliance with this agreement and answers reasonable audit questionnaires. On-site audits are agreed in advance, at the Controller's cost, with reasonable notice.

## 13. Contact

{{company}} · {{address}} · {{email}}
MD;

    private const DPA_FR = <<<'MD'
# Accord de traitement des données

Cet accord fait partie des conditions générales entre l'hôtel (« le Responsable ») et **{{company}}** (« le Sous-traitant »), exploitant de **{{brand}}**. Il s'applique aux données personnelles des clients du Responsable que le Sous-traitant traite pour fournir le service.

## 1. Objet et durée

Le Sous-traitant traite les données des clients uniquement pour fournir le service : répondre aux messages WhatsApp avec l'assistant, gérer réservations, paiements et notes de chambre, envoyer les messages liés au séjour et assister l'équipe du Responsable. L'accord dure aussi longtemps que les conditions générales.

## 2. Données et personnes concernées

Clients et personnes qui contactent le Responsable : nom, numéro WhatsApp, e-mail, messages et notes vocales, détails de réservation et demandes particulières, commandes et notes, état des paiements, langue.

## 3. Instructions

Le Sous-traitant traite les données uniquement sur instructions documentées du Responsable : les conditions générales, le présent accord et les réglages du Responsable dans la plateforme. Il informe le Responsable si une instruction lui semble illicite.

## 4. Confidentialité

Les personnes autorisées à traiter les données sont tenues à la confidentialité et n'y accèdent que lorsque leur travail l'exige.

## 5. Sécurité

Le Sous-traitant maintient des mesures de sécurité appropriées : connexions chiffrées, chiffrement des valeurs sensibles et des sauvegardes, sauvegardes quotidiennes, accès selon les rôles, double authentification des propriétaires, séparation entre hôtels, journaux d'audit et surveillance.

## 6. Sous-traitants ultérieurs

Le Responsable autorise les sous-traitants ultérieurs suivants : MCSpoorthi IT Solutions Private Limited (Mayura Consultancy Services) pour le développement, l'exploitation et le support ; Meta Platforms pour WhatsApp ; OpenAI et, en secours, un autre fournisseur d'IA ; Brevo pour les e-mails ; l'hébergeur et prestataire de sauvegarde ; Sentry pour le suivi des erreurs. Le Sous-traitant impose à chacun des obligations équivalentes et informe le Responsable à l'avance de tout changement, afin qu'il puisse s'y opposer.

## 7. Transferts internationaux

Les transferts hors de Côte d'Ivoire sont limités à ce dont chaque sous-traitant a besoin et encadrés par des garanties appropriées.

## 8. Droits des personnes

La plateforme permet au Responsable de retrouver, exporter et anonymiser les données d'un client. Le Sous-traitant aide le Responsable à répondre aux demandes et lui transmet toute demande reçue directement.

## 9. Violations de données

Le Sous-traitant notifie le Responsable dans les meilleurs délais et au plus tard 48 heures après en avoir eu connaissance, avec les informations disponibles pour l'aider à remplir ses propres obligations.

## 10. Assistance

Le Sous-traitant aide le Responsable en matière de sécurité, d'analyses d'impact et de relations avec l'autorité (ARTCI), dans la mesure des informations dont il dispose.

## 11. Fin du service

À la fin du contrat, le Responsable peut exporter ses données pendant 90 jours. Le Sous-traitant les supprime ensuite, y compris des sauvegardes à leur expiration, sauf ce que la loi l'oblige à conserver.

## 12. Audits

Le Sous-traitant met à disposition les informations nécessaires pour démontrer le respect du présent accord et répond aux questionnaires d'audit raisonnables. Les audits sur place sont convenus à l'avance, aux frais du Responsable, avec un préavis raisonnable.

## 13. Contact

{{company}} · {{address}} · {{email}}
MD;
}
