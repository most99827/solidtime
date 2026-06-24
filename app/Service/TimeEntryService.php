<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\TimeEntryRoundingType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class TimeEntryService
{
    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public function getStartSelectRawForRounding(?TimeEntryRoundingType $roundingType, ?int $roundingMinutes): string
    {
        if ($roundingType === null || $roundingMinutes === null) {
            return 'start';
        }
        if ($roundingMinutes < 1) {
            throw new LogicException('Rounding minutes must be greater than 0');
        }

        if ($this->isMysql()) {
            $seconds = $roundingMinutes * 60;
            return 'FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(start) / '.$seconds.') * '.$seconds.')';
        }

        return 'date_bin(\'1 minutes\', start, TIMESTAMP \'1970-01-01\')';
    }

    public function getEndSelectRawForRounding(?TimeEntryRoundingType $roundingType, ?int $roundingMinutes): string
    {
        $now = Carbon::now()->toDateTimeString();
        if ($roundingType === null || $roundingMinutes === null) {
            return 'COALESCE(`end`, \''.$now.'\')';
        }
        if ($roundingMinutes < 1) {
            throw new LogicException('Rounding minutes must be greater than 0');
        }
        $end = 'COALESCE(`end`, \''.$now.'\')';
        $start = $this->getStartSelectRawForRounding($roundingType, $roundingMinutes);

        if ($this->isMysql()) {
            $seconds = $roundingMinutes * 60;
            $endUnix = 'UNIX_TIMESTAMP('.$end.')';
            $startUnix = 'UNIX_TIMESTAMP('.$start.')';
            if ($roundingType === TimeEntryRoundingType::Down) {
                return 'FROM_UNIXTIME(FLOOR(('.$endUnix.' - '.$startUnix.') / '.$seconds.') * '.$seconds.' + '.$startUnix.')';
            } elseif ($roundingType === TimeEntryRoundingType::Up) {
                return 'FROM_UNIXTIME(CEIL(('.$endUnix.' - '.$startUnix.') / '.$seconds.') * '.$seconds.' + '.$startUnix.')';
            } elseif ($roundingType === TimeEntryRoundingType::Nearest) {
                return 'FROM_UNIXTIME(ROUND(('.$endUnix.' - '.$startUnix.') / '.$seconds.') * '.$seconds.' + '.$startUnix.')';
            }
        }

        if ($roundingType === TimeEntryRoundingType::Down) {
            return 'date_bin(\''.$roundingMinutes.' minutes\', '.$end.', '.$start.')';
        } elseif ($roundingType === TimeEntryRoundingType::Up) {
            return 'CASE WHEN '.$end.' = date_bin(\''.$roundingMinutes.' minutes\', '.$end.', '.$start.') '.
                   'THEN '.$end.' '.
                   'ELSE date_bin(\''.$roundingMinutes.' minutes\', '.$end.' + interval \''.$roundingMinutes.' minutes\', '.$start.') '.
                   'END';
        } elseif ($roundingType === TimeEntryRoundingType::Nearest) {
            return 'date_bin(\''.$roundingMinutes.' minutes\', '.$end.' + interval \''.($roundingMinutes / 2).' minutes\', '.$start.')';
        }
    }
}
