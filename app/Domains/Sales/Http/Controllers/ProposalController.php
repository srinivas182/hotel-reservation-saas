<?php

declare(strict_types=1);

namespace App\Domains\Sales\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Sales\Mail\ProposalMail;
use App\Domains\Sales\Models\Proposal;
use App\Domains\Sales\ProposalView;
use App\Domains\Site\Models\Lead;
use App\Http\Controllers\Controller;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * H: proposals — prepared by sales from a lead, read by the prospect at
 * /proposal/{token} (or as a PDF), accepted with "Start my trial".
 */
final class ProposalController extends Controller
{
    public function store(Request $request, Lead $lead, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('active', true)],
            'currency' => ['required', 'string', 'size:3'],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'discount_percent' => ['required', 'integer', 'min:0', 'max:'.($user->platformCan(\App\Domains\Platform\PlatformAbility::Configuration) ? 50 : 20)],
            'roi' => ['required', 'array'],
            'roi.enquiries_per_day' => ['required', 'numeric', 'min:0', 'max:1000'],
            'roi.missed_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'roi.conversion_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'roi.average_nights' => ['required', 'numeric', 'min:1', 'max:30'],
            'roi.night_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'locale' => ['required', Rule::in(['fr', 'en'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'send' => ['nullable', 'boolean'],
        ]);

        $plan = \App\Domains\Billing\Models\Plan::query()->whereKey((string) $data['plan_id'])->firstOrFail();
        if ($plan->price((string) $data['currency'], (string) $data['cycle']) === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['currency' => __('This plan has no price in this currency.')]);
        }

        $proposal = Proposal::query()->create([
            'lead_id' => $lead->id, 'plan_id' => $plan->id, 'currency' => strtoupper((string) $data['currency']), 'cycle' => $data['cycle'],
            'discount_percent' => (int) $data['discount_percent'], 'roi' => array_map(fn ($v) => +$v, (array) $data['roi']), 'locale' => $data['locale'],
            'note' => $data['note'] ?? null, 'token' => Str::random(40), 'valid_until' => now()->addDays(30)->toDateString(), 'created_by' => $user->id,
        ]);

        if ((bool) ($data['send'] ?? false) && $lead->email !== null) {
            Mail::to($lead->email)->queue(new ProposalMail($proposal->id));
            $proposal->forceFill(['sent_at' => now()])->save();
        }
        Audit::record('sales.proposal_created', 'Proposal prepared', causer: $user, properties: ['lead' => $lead->id, 'proposal' => $proposal->id]);

        return back()->with('proposal_link', url('/proposal/'.$proposal->token));
    }

    public function show(string $token): View
    {
        $proposal = $this->find($token);
        App::setLocale($proposal->locale);
        if ($proposal->viewed_at === null) {
            $proposal->forceFill(['viewed_at' => now()])->save();
        }

        return view('sales.proposal', [...ProposalView::data($proposal), 'pdf' => false]);
    }

    public function pdf(string $token): Response
    {
        $proposal = $this->find($token);
        App::setLocale($proposal->locale);

        return Pdf::loadView('sales.proposal', [...ProposalView::data($proposal), 'pdf' => true])->setPaper('a4')
            ->download(Str::slug((string) $proposal->lead->hotel_name).'-proposal.pdf');
    }

    /** "Start my trial": a one-use invitation code linked to the lead, then sign-up. */
    public function accept(string $token): RedirectResponse
    {
        $proposal = $this->find($token);
        abort_if($proposal->expired(), 410);

        $code = DB::transaction(function () use ($proposal): SignupCode {
            $proposal->refresh();
            if ($proposal->code !== null) {
                return $proposal->code;
            }
            $code = SignupCode::query()->create(['code' => Str::upper(Str::random(8)), 'note' => 'Proposal #'.$proposal->id.' · '.$proposal->lead->hotel_name, 'max_uses' => 1, 'expires_at' => now()->addDays(30)]);
            $proposal->forceFill(['signup_code_id' => $code->id, 'accepted_at' => now()])->save();
            if ($proposal->lead->signup_code_id === null) {
                $proposal->lead->update(['signup_code_id' => $code->id]);
            }

            return $code;
        });

        return redirect('/signup?code='.$code->code);
    }

    private function find(string $token): Proposal
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return Proposal::query()->with(['lead', 'plan', 'code'])->where('token', $token)->firstOrFail();
    }
}
