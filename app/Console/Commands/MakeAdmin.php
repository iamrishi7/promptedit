<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:make-admin {email : The user to grant the admin role} {--revoke : Remove the admin role instead}')]
#[Description('Grant (or revoke) the admin role, which opens the spend and profit report')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->removeRole(User::ADMIN_ROLE);
            $this->info("{$user->email} is no longer an admin.");

            return self::SUCCESS;
        }

        $user->assignRole(User::ADMIN_ROLE);
        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
