<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\TransactionRunner;
use Closure;
use Illuminate\Support\Facades\DB;

final class LaravelTransactionRunner implements TransactionRunner
{
    /**
     * Up to 3 attempts in total if the database reports a deadlock. Single-row FOR UPDATE locks make
     * concurrent updates of the same generation wait for each other rather than fail.
     */
    public function run(Closure $callback): mixed
    {
        return DB::transaction($callback, attempts: 3);
    }
}
