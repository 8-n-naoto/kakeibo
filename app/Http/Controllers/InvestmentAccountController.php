<?php

namespace App\Http\Controllers;

use App\Models\InvestmentAccount;
use App\Support\DomainLimits;
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

        // (account_type, fiscal_year) は unique。同じ組み合わせで登録し直すと
        // 拠出額も評価額もメモも黙って置き換わっていた。置き換えは明示的に選ばせる。
        $existing = $this->sameAccount($validated)->first();

        if ($existing !== null && ! $request->boolean('overwrite')) {
            return back(fallback: route('investment-accounts.create'))->withInput()->withErrors([
                'fiscal_year' => sprintf(
                    '%d年度の%sはすでに登録されています（拠出 ¥%s・評価額 ¥%s%s）。'
                        .'置き換えるなら「すでにある口座は上書きする」にチェックを入れてください。',
                    $existing->fiscal_year,
                    InvestmentAccount::TYPES[$existing->account_type] ?? $existing->account_type,
                    number_format($existing->contributed_amount),
                    number_format($existing->evaluation_amount),
                    $existing->memo ? '／メモ「'.$existing->memo.'」' : '',
                ),
            ]);
        }

        InvestmentAccount::updateOrCreate(
            ['account_type' => $validated['account_type'], 'fiscal_year' => $validated['fiscal_year']],
            $validated
        );

        return redirect()->route('investment-accounts.index', ['fiscal_year' => $validated['fiscal_year']])
            ->with('status', $existing !== null ? '投資口座を上書きしました。' : '投資口座を登録しました。');
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

        // 年度を打ち替えたときに、その年度・種別が既にあると
        // SQLSTATE[23000] で 500 になり、入力も消える
        $conflict = $this->sameAccount($validated)->whereKeyNot($investmentAccount->getKey())->first();

        if ($conflict !== null) {
            return back(fallback: route('investment-accounts.edit', $investmentAccount))->withInput()->withErrors([
                'fiscal_year' => sprintf(
                    '%d年度の%sはすでに別に登録されています。先にそちらを削除するか、別の年度を選んでください。',
                    $conflict->fiscal_year,
                    InvestmentAccount::TYPES[$conflict->account_type] ?? $conflict->account_type,
                ),
            ]);
        }

        $investmentAccount->update($validated);

        return redirect()->route('investment-accounts.index', ['fiscal_year' => $validated['fiscal_year']])
            ->with('status', '投資口座を更新しました。');
    }

    /**
     * 同じ年度・同じ種別の口座を引くクエリ。
     *
     * @param  array<string, mixed>  $validated
     * @return \Illuminate\Database\Eloquent\Builder<InvestmentAccount>
     */
    private function sameAccount(array $validated)
    {
        return InvestmentAccount::query()
            ->where('account_type', $validated['account_type'])
            ->where('fiscal_year', $validated['fiscal_year']);
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
            'annual_limit' => array_merge(['required'], DomainLimits::amountRules()),
            'contributed_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'evaluation_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
