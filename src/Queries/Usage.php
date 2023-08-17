<?php

namespace Laravel\Pulse\Queries;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval as Interval;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Laravel\Pulse\Pulse;

/**
 * @interval
 */
class Usage
{
    /**
     * Create a new query instance.
     */
    public function __construct(
        protected Connection $connection,
        protected Repository $config,
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Run the query.
     */
    public function __invoke(Interval $interval, string $type): Collection
    {
        $now = new CarbonImmutable;

        $top10 = $this->connection->query()
            ->when($type === 'dispatched_job_counts',
                fn ($query) => $query->from('pulse_jobs'),
                fn ($query) => $query->from('pulse_requests'))
            ->selectRaw('user_id, COUNT(*) as count')
            ->whereNotNull('user_id')
            ->where('date', '>=', $now->subSeconds($interval->totalSeconds)->toDateTimeString())
            ->when($type === 'slow_endpoint_counts',
                fn ($query) => $query->where('duration', '>=', $this->config->get('pulse.slow_endpoint_threshold')))
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $users = $this->pulse->resolveUsers($top10->pluck('user_id'));

        return $top10->map(function ($row) use ($users) {
            $user = $users->firstWhere('id', $row->user_id);

            return $user ? [
                'count' => $row->count,
                'user' => [
                    'name' => $user['name'],
                    // "extra" rather than 'email'
                    // avatar for pretty-ness?
                    'email' => $user['email'] ?? null,
                ],
            ] : null;
        })
        ->filter()
        ->values();
    }
}