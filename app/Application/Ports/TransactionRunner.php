<?php

declare(strict_types=1);

namespace App\Application\Ports;

use Closure;

interface TransactionRunner
{
    /**
     * Run the callback in one database transaction, rolling back if it throws.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Closure $callback): mixed;
}
