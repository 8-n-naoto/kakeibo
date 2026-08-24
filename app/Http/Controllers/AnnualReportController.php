<?php

namespace App\Http\Controllers;

use App\Services\AnnualReportService;
use App\Support\MonthParser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 1年分の家計を振り返るレポート。
 */
class AnnualReportController extends Controller
{
    /** 表示を許す年の上限（今年から何年先まで） */
    public const MAX_YEARS_AHEAD = 1;

    public function __construct(private readonly AnnualReportService $service)
    {
    }

    public function __invoke(Request $request, ?int $year = null)
    {
        $year = $this->resolveYear($year ?? $request->input('year'));

        // URL直打ちで取引の無い年を開いても、セレクタの表示と見出しがずれないようにする
        $availableYears = collect($this->service->availableYears())
            ->push($year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return view('reports.annual', [
            'summary' => $this->service->summary($year),
            'availableYears' => $availableYears,
        ]);
    }

    private function resolveYear(mixed $year): int
    {
        $year = (int) $year;
        $thisYear = (int) Carbon::now()->format('Y');

        // 実在しない年を指定されたら今年に寄せる
        if ($year < MonthParser::MIN_YEAR || $year > $thisYear + self::MAX_YEARS_AHEAD) {
            return $thisYear;
        }

        return $year;
    }
}
