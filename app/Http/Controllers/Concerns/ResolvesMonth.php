<?php

namespace App\Http\Controllers\Concerns;

use App\Support\MonthParser;
use Illuminate\Support\Carbon;

/**
 * `month=YYYY-MM` を受け取るコントローラ用。判定の中身は MonthParser にある。
 */
trait ResolvesMonth
{
    protected function resolveMonth(mixed $month): Carbon
    {
        return MonthParser::parseOrCurrent($month);
    }
}
