<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PostgresConnectionUsage
{
    /**
     * Measure only client backends because max_connections does not represent
     * PostgreSQL background workers such as checkpointer, WAL writer or cron.
     *
     * @return array{
     *     used: int,
     *     active: int,
     *     idle: int,
     *     idle_in_transaction: int,
     *     maximum: int,
     *     reserved: int,
     *     usable: int,
     *     percent: float
     * }
     */
    public function measure(): array
    {
        $usage = DB::selectOne(<<<'SQL'
            select
                count(*) filter (
                    where backend_type = 'client backend'
                )::int as used_connections,
                count(*) filter (
                    where backend_type = 'client backend'
                      and state = 'active'
                )::int as active_connections,
                count(*) filter (
                    where backend_type = 'client backend'
                      and state = 'idle'
                )::int as idle_connections,
                count(*) filter (
                    where backend_type = 'client backend'
                      and state = 'idle in transaction'
                )::int as idle_in_transaction_connections,
                current_setting('max_connections')::int as max_connections,
                current_setting('superuser_reserved_connections')::int as reserved_connections
            from pg_stat_activity
            SQL);

        $used = max((int) ($usage->used_connections ?? 0), 0);
        $active = max((int) ($usage->active_connections ?? 0), 0);
        $idle = max((int) ($usage->idle_connections ?? 0), 0);
        $idleInTransaction = max((int) ($usage->idle_in_transaction_connections ?? 0), 0);
        $maximum = max((int) ($usage->max_connections ?? 0), 1);
        $reserved = max((int) ($usage->reserved_connections ?? 0), 0);
        $usable = max($maximum - $reserved, 1);

        return [
            'used' => $used,
            'active' => $active,
            'idle' => $idle,
            'idle_in_transaction' => $idleInTransaction,
            'maximum' => $maximum,
            'reserved' => $reserved,
            'usable' => $usable,
            'percent' => round(($used / $usable) * 100, 2),
        ];
    }
}
