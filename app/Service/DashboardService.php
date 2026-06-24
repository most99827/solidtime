<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\Weekday;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private TimezoneService $timezoneService;

    public function __construct(TimezoneService $timezoneService)
    {
        $this->timezoneService = $timezoneService;
    }

    /**
     * @return Collection<int, string>
     */
    private function lastDays(int $days, CarbonTimeZone $timeZone): Collection
    {
        $result = new Collection;
        $date = Carbon::now($timeZone)->subDays($days);
        for ($i = 0; $i < $days; $i++) {
            $date->addDay();
            $result->push($date->format('Y-m-d'));
        }

        return $result;
    }

    /**
     * @return array{start: string, end: string, dates: array<string, array<string>>}
     */
    private function lastDaysSplitInWindows(int $days, CarbonTimeZone $timeZone, int $windows): array
    {
        $result = [];
        $windowSize = 24 / $windows;
        $end = Carbon::now($timeZone)->startOfDay()->addDay()->subHours(3)->utc()->toDateTimeString();
        $start = Carbon::now($timeZone)->subDays($days)->startOfDay()->utc()->toDateTimeString();

        $date = Carbon::now($timeZone)->startOfDay();
        $dateUtc = Carbon::now($timeZone)->startOfDay()->utc();
        for ($i = 0; $i < $days; $i++) {
            $dateString = $date->format('Y-m-d');
            $tempDate = $dateUtc->copy();
            $start = $tempDate->copy()->utc()->toDateTimeString();
            $tempWindows = [];
            for ($j = 0; $j < $windows; $j++) {
                $tempWindow = $tempDate->toDateTimeString();
                $tempWindows[] = $tempWindow;
                $tempDate->addHours($windowSize);
            }
            $result[$dateString] = $tempWindows;
            $date->subDay();
            $dateUtc->subDay();
        }

        return [
            'start' => $start,
            'end' => $end,
            'dates' => $result,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function daysOfThisWeek(CarbonTimeZone $timeZone, Weekday $startOfWeek): Collection
    {
        $result = new Collection;
        $date = Carbon::now($timeZone);
        $start = $date->startOfWeek($startOfWeek->carbonWeekDay());
        for ($i = 0; $i < 7; $i++) {
            $result->push($start->format('Y-m-d'));
            $start->addDay();
        }

        return $result;
    }

    /**
     * @param  Collection<int, string>  $possibleDates
     * @param  Builder<TimeEntry>  $builder
     * @return Builder<TimeEntry>
     */
    private function constrainDateByPossibleDates(Builder $builder, Collection $possibleDates, CarbonTimeZone $timeZone): Builder
    {
        $value1 = Carbon::createFromFormat('Y-m-d', $possibleDates->first(), $timeZone);
        $value2 = Carbon::createFromFormat('Y-m-d', $possibleDates->last(), $timeZone);
        if ($value2 === null || $value1 === null) {
            throw new \RuntimeException('Provided date is not valid');
        }
        if ($value1->gt($value2)) {
            $last = $value1;
            $first = $value2;
        } else {
            $last = $value2;
            $first = $value1;
        }

        return $builder->whereBetween('start', [
            $first->startOfDay()->utc(),
            $last->endOfDay()->utc(),
        ]);
    }

    /**
     * @param  Builder<TimeEntry>  $builder
     * @return Builder<TimeEntry>
     */
    private function constrainDateByCurrentWeek(Builder $builder, CarbonTimeZone $timeZone, Weekday $startOfWeek): Builder
    {
        return $builder->whereBetween('start', [
            Carbon::now($timeZone)->startOfWeek($startOfWeek->carbonWeekDay())->utc(),
            Carbon::now($timeZone)->endOfWeek($startOfWeek->toEndOfWeek()->carbonWeekDay())->utc(),
        ]);
    }

    /**
     * Get the daily tracked hours for the user
     * First value: date
     * Second value: seconds
     *
     * @return array<int, array{date: string, duration: int}>
     */
    public function getDailyTrackedHours(User $user, Organization $organization, int $days): array
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);
        $timezoneShift = $this->timezoneService->getShiftFromUtc($timezone);

        if ($timezoneShift > 0) {
            $dateWithTimeZone = 'DATE_ADD(start, INTERVAL '.$timezoneShift.' SECOND)';
        } elseif ($timezoneShift < 0) {
            $dateWithTimeZone = 'DATE_SUB(start, INTERVAL '.abs($timezoneShift).' SECOND)';
        } else {
            $dateWithTimeZone = 'start';
        }

        $possibleDays = $this->lastDays($days, $timezone);

        $query = TimeEntry::query()
            ->select(DB::raw('DATE('.$dateWithTimeZone.') as date, round(sum(TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())))) as aggregate'))
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey())
            ->groupBy(DB::raw('DATE('.$dateWithTimeZone.')'))
            ->orderBy('date');

        $query = $this->constrainDateByPossibleDates($query, $possibleDays, $timezone);
        $resultDb = $query->get()
            ->pluck('aggregate', 'date');

        $result = [];

        foreach ($possibleDays as $possibleDay) {
            $result[] = [
                'date' => $possibleDay,
                'duration' => (int) ($resultDb->get($possibleDay) ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Statistics for the current week starting at weekday of users preference
     *
     * @return array<int, array{date: string, duration: int}>
     */
    public function getWeeklyHistory(User $user, Organization $organization): array
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);
        $timezoneShift = $this->timezoneService->getShiftFromUtc($timezone);
        if ($timezoneShift > 0) {
            $dateWithTimeZone = 'DATE_ADD(start, INTERVAL '.$timezoneShift.' SECOND)';
        } elseif ($timezoneShift < 0) {
            $dateWithTimeZone = 'DATE_SUB(start, INTERVAL '.abs($timezoneShift).' SECOND)';
        } else {
            $dateWithTimeZone = 'start';
        }
        $possibleDays = $this->daysOfThisWeek($timezone, $user->week_start);

        $query = TimeEntry::query()
            ->select(DB::raw('DATE('.$dateWithTimeZone.') as date, round(sum(TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())))) as aggregate'))
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey())
            ->groupBy(DB::raw('DATE('.$dateWithTimeZone.')'))
            ->orderBy('date');

        $query = $this->constrainDateByPossibleDates($query, $possibleDays, $timezone);
        $resultDb = $query->get()
            ->pluck('aggregate', 'date');

        $result = [];

        foreach ($possibleDays as $possibleDay) {
            $result[] = [
                'date' => $possibleDay,
                'duration' => (int) ($resultDb->get($possibleDay) ?? 0),
            ];
        }

        return $result;
    }

    public function totalWeeklyTime(User $user, Organization $organization): int
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);
        $possibleDays = $this->daysOfThisWeek($timezone, $user->week_start);

        $query = TimeEntry::query()
            ->select(DB::raw('round(sum(TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())))) as aggregate'))
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey());

        $query = $this->constrainDateByPossibleDates($query, $possibleDays, $timezone);
        /** @var Collection<int, object{aggregate: int}> $resultDb */
        $resultDb = $query->get();

        return (int) $resultDb->get(0)->aggregate;
    }

    public function totalWeeklyBillableTime(User $user, Organization $organization): int
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);
        $possibleDays = $this->daysOfThisWeek($timezone, $user->week_start);

        $query = TimeEntry::query()
            ->select(DB::raw('round(sum(TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())))) as aggregate'))
            ->where('billable', '=', true)
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey());

        $query = $this->constrainDateByPossibleDates($query, $possibleDays, $timezone);
        /** @var Collection<int, object{aggregate: int}> $resultDb */
        $resultDb = $query->get();

        return (int) $resultDb->get(0)->aggregate;
    }

    /**
     * @return array{value: int, currency: string}
     */
    public function totalWeeklyBillableAmount(User $user, Organization $organization): array
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);
        $possibleDays = $this->daysOfThisWeek($timezone, $user->week_start);

        $query = TimeEntry::query()
            ->select(DB::raw('
               round(
                    sum(
                        TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())) * (COALESCE(billable_rate, 0) / 3600.0)
                    )
               ) as aggregate'))
            ->where('billable', '=', true)
            ->whereNotNull('billable_rate')
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey());

        $query = $this->constrainDateByPossibleDates($query, $possibleDays, $timezone);
        /** @var Collection<int, object{aggregate: int}> $resultDb */
        $resultDb = $query->get();

        return [
            'value' => (int) $resultDb->get(0)->aggregate,
            'currency' => $organization->currency,
        ];
    }

    /**
     * @return array<int, array{value: int, name: string, color: string}>
     */
    public function weeklyProjectOverview(User $user, Organization $organization): array
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);

        $query = TimeEntry::query()
            ->select(DB::raw('project_id, round(sum(TIMESTAMPDIFF(SECOND, start, COALESCE(end, NOW())))) as aggregate'))
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey())
            ->groupBy('project_id');

        $query = $this->constrainDateByCurrentWeek($query, $timezone, $user->week_start);
        /** @var Collection<int, object{project_id: string, aggregate: int}> $entries */
        $entries = $query->get();

        $projectIds = $entries->pluck('project_id')->whereNotNull()->all();
        $projectsMap = Project::query()
            ->select(['id', 'name', 'color'])
            ->whereBelongsTo($organization, 'organization')
            ->whereIn('id', $projectIds)
            ->get()
            ->keyBy('id');

        $response = [];

        $aggregateOther = 0;

        foreach ($entries as $entry) {
            $project = $projectsMap->get($entry->project_id);
            if ($project === null) {
                $aggregateOther += (int) $entry->aggregate;

                continue;
            }

            $response[] = [
                'value' => (int) $entry->aggregate,
                'id' => $entry->project_id,
                'name' => $project->name,
                'color' => $project->color,
            ];
        }

        if ($aggregateOther > 0 || count($response) === 0) {
            $response[] = [
                'value' => $aggregateOther,
                'id' => null,
                'name' => 'No project',
                'color' => '#cccccc',
            ];

        }

        return $response;
    }

    /**
     * Rhe 4 most recently active members of your team with member_id, name, description of the latest time entry, time_entry_id, task_id and a boolean status if the team member is currently working
     *
     * @return array<int, array{member_id: string, name: string, description: string|null, time_entry_id: string, task_id: string|null, status: bool }>
     */
    public function latestTeamActivity(Organization $organization): array
    {
        $timeEntries = TimeEntry::query()
            ->from(DB::raw('(SELECT t.*, ROW_NUMBER() OVER (PARTITION BY member_id ORDER BY start DESC) AS rn FROM time_entries t WHERE t.organization_id = ?) AS ranked_entries'))
            ->select(DB::raw('member_id, description, id, task_id, start, `end`'))
            ->addBinding($organization->getKey(), 'from')
            ->where('rn', '=', 1)
            ->with([
                'member' => [
                    'user',
                ],
            ])
            ->get()
            ->sortByDesc('start')
            ->slice(0, 4);

        $response = [];

        foreach ($timeEntries as $timeEntry) {
            $response[] = [
                'member_id' => $timeEntry->member_id,
                'name' => $timeEntry->member->user->name,
                'description' => $timeEntry->description,
                'time_entry_id' => $timeEntry->id,
                'task_id' => $timeEntry->task_id,
                'status' => $timeEntry->end === null,
            ];
        }

        return $response;
    }

    /**
     * The 4 tasks with the most recent time entries
     *
     * @return array<int, array{id: string, name: string, project_name: string|null, project_id: string }>
     */
    public function latestTasks(User $user, Organization $organization): array
    {
        $tasks = Task::query()
            ->where('organization_id', '=', $organization->getKey())
            ->with([
                'project',
            ])
            ->whereHas('timeEntries', function (Builder $builder) use ($user, $organization): void {
                /** @var Builder<TimeEntry> $builder */
                $builder->where('user_id', '=', $user->getKey())
                    ->where('organization_id', '=', $organization->getKey());
            })
            ->orderByDesc(
                TimeEntry::select('start')
                    ->whereColumn('task_id', 'tasks.id')
                    ->orderBy('start', 'desc')
                    ->limit(1)
            )
            ->limit(4)
            ->get();

        $response = [];

        foreach ($tasks as $task) {
            $response[] = [
                'id' => $task->id,
                'name' => $task->name,
                'project_name' => $task->project->name,
                'project_id' => $task->project->id,
            ];
        }

        return $response;
    }

    /**
     * The last 7 days with statistics for the time entries
     *
     * @return array<int, array{ date: string, duration: int, history: array<int> }>
     */
    public function lastSevenDays(User $user, Organization $organization): array
    {
        $timezone = $this->timezoneService->getTimezoneFromUser($user);
        $lastDaysSplitInWindows = $this->lastDaysSplitInWindows(7, $timezone, 8);
        $windowStarts = collect($lastDaysSplitInWindows['dates'])->flatMap(function ($windows) {
            return collect($windows)->mapWithKeys(fn ($w) => [$w => 0]);
        })->toArray();

        // Fetch time entries in range and calculate overlap per window in PHP
        $entries = TimeEntry::query()
            ->where('user_id', '=', $user->getKey())
            ->where('organization_id', '=', $organization->getKey())
            ->where('start', '<', $lastDaysSplitInWindows['end'])
            ->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($lastDaysSplitInWindows): void {
                $q->whereNull('end')
                  ->orWhere('end', '>', $lastDaysSplitInWindows['start']);
            })
            ->get(['start', 'end']);

        foreach ($windowStarts as $windowStart => &$duration) {
            $windowEnd = Carbon::parse($windowStart)->addHours(3);
            $ws = Carbon::parse($windowStart);
            $we = $windowEnd;
            foreach ($entries as $entry) {
                $es = Carbon::parse($entry->start);
                $ee = $entry->end !== null ? Carbon::parse($entry->end) : Carbon::now();
                if ($es->lt($we) && $ee->gt($ws)) {
                    $overlapStart = $es->max($ws);
                    $overlapEnd = $ee->min($we);
                    $duration += $overlapStart->diffInSeconds($overlapEnd);
                }
            }
        }
        unset($duration);
        $data = collect($windowStarts);

        $response = [];

        foreach ($lastDaysSplitInWindows['dates'] as $date => $windows) {
            $history = [];
            $duration = 0;
            foreach ($windows as $window) {
                $value = (int) ($data->get($window, null) ?? 0);
                $history[] = $value;
                $duration += $value;
            }
            $response[] = [
                'date' => $date,
                'duration' => $duration,
                'history' => $history,
            ];
        }

        return $response;
    }
}
