<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Ports\TransactionRunner;
use Closure;

final class ImmediateTransactionRunner implements TransactionRunner
{
    public function run(Closure $callback): mixed
    {
        return $callback();
    }
}
