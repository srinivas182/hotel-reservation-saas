<?php

declare(strict_types=1);

use App\Domains\Assistant\Engine\WhatsAppText;

it('turns Markdown into WhatsApp formatting and keeps short paragraphs', function (string $in, string $out): void {
    expect(WhatsAppText::tidy($in))->toBe($out);
})->with([
    'bold' => ['La **Suite** coûte **60 000 XOF**.', 'La *Suite* coûte *60 000 XOF*.'],
    'bullets' => ["Chambres :\n- Standard\n* Suite\n• Exécutive", "Chambres :\n• Standard\n• Suite\n• Exécutive"],
    'heading' => ["### Vos options\nStandard", "*Vos options*\nStandard"],
    'spacing' => ["Bonjour !\n\n\n\nVoici les prix.   \n\n", "Bonjour !\n\nVoici les prix."],
    'already fine' => ["*Suite* : *60 000 XOF*\n\nSouhaitez-vous réserver ?", "*Suite* : *60 000 XOF*\n\nSouhaitez-vous réserver ?"],
]);
