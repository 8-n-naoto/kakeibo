<?php

namespace App\Http\Controllers;

use App\Models\SavingsGoal;
use App\Support\DomainLimits;
use Illuminate\Http\Request;

class SavingsGoalController extends Controller
{
    public function index()
    {
        $goals = SavingsGoal::orderByRaw('target_date IS NULL, target_date asc')->get();

        return view('savings_goals.index', compact('goals'));
    }

    public function create()
    {
        return view('savings_goals.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        SavingsGoal::create($validated);

        return redirect()->route('savings-goals.index')->with('status', '貯蓄目標を登録しました。');
    }

    public function edit(SavingsGoal $savingsGoal)
    {
        return view('savings_goals.edit', ['goal' => $savingsGoal]);
    }

    public function update(Request $request, SavingsGoal $savingsGoal)
    {
        $validated = $this->validated($request);

        $savingsGoal->update($validated);

        return redirect()->route('savings-goals.index')->with('status', '貯蓄目標を更新しました。');
    }

    public function destroy(SavingsGoal $savingsGoal)
    {
        $savingsGoal->delete();

        return redirect()->route('savings-goals.index')->with('status', '貯蓄目標を削除しました。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'target_amount' => array_merge(['required'], DomainLimits::amountRules(1)),
            'current_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'target_date' => array_merge(['nullable'], DomainLimits::dateRules()),
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
