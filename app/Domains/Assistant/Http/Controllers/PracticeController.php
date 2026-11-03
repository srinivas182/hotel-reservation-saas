<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Http\Controllers;

use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The practice screen: staff try the assistant. Nothing is sent to anyone.
 */
final class PracticeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('hotel/assistant-practice');
    }

    public function run(Request $request, AssistantEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'turns' => ['required', 'array', 'min:1', 'max:'.AssistantEngine::HISTORY_LIMIT],
            'turns.*.role' => ['required', 'in:guest,hotel'],
            'turns.*.text' => ['required', 'string', 'max:2000'],
        ]);

        $result = $engine->respond(array_map(
            fn (array $turn): AssistantTurn => new AssistantTurn((string) $turn['role'], (string) $turn['text']),
            array_values($data['turns']),
        ));

        Inertia::flash('practice', [
            ...$result->toArray(),
            'cost' => round($result->costMicroUsd / 1_000_000, 4),
        ]);

        return back();
    }
}
