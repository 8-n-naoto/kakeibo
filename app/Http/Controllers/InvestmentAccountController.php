<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use Illuminate\Http\Request;

class InvestmentAccountController extends Controller
{
    public function index(Request $request)
    {
        $fiscalYear = (int) $request->input('fiscal_year', now()->year);

        $accounts = InvestmentAccount::where('fiscal_year', $fiscalYear)
            ->orderBy('account_type')
            ->get();

        $years = InvestmentAccount::select('fiscal_year')->distinct()->orderByDesc('fiscal_year')->pluck('fiscal_year');
        if ($years->isEmpty() || ! $years->contains($fiscalYear)) {
            $years->push($fiscalYear);
            $years = $years->sortDesc()->values();
        }

        return view('investment_accounts.index', [
            'accounts' => $accounts,
            'fiscalYear' => $fiscalYear,
            'years' => $years,
            'accountTypes' => InvestmentAccount::TYPES,
        ]);
    }

    public function create()
    {
        return view('investment_accounts.create', ['accountTypes' => InvestmentAccount::TYPES]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        InvestmentAccount::updateOrCreate(
            ['account_type' => $validated['account_type'], 'fiscal_year' => $validated['fiscal_year']],
            $validated
        );

        return redirect()->route('investment-accounts.index', ['fiscal_year' => $validated['fiscal_year']])
            ->with('status', '投資口座を登録しました。');
    }

    public function edit(InvestmentAccount $investmentAccount)
    {
        return view('investment_accounts.edit', [
            'account' => $investmentAccount,
            'accountTypes' => InvestmentAccount::TYPES,
        ]);
    }

    public function update(Request $request, InvestmentAccount $investmentAccount)
    {
        $validated = $this->validated($request);

        $investmentAccount->update($validated);

        return redirect()->route('investment-accounts.index', ['fiscal_year' => $validated['fiscal_year']])
            ->with('status', '投資口座を更新しました。');
    }

    public function destroy(InvestmentAccount $investmentAccount)
    {
        $year = $investmentAccount->fiscal_year;
        $investmentAccount->delete();

        return redirect()->route('investment-accounts.index', ['fiscal_year' => $year])
            ->with('status', '投資口座を削除しました。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'account_type' => ['required', 'in:'.implode(',', array_keys(InvestmentAccount::TYPES))],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'annual_limit' => ['required', 'integer', 'min:0'],
            'contributed_amount' => ['required', 'integer', 'min:0'],
            'evaluation_amount' => ['required', 'integer', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
