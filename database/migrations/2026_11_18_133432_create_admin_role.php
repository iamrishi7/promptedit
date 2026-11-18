<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The one role the app has. Created by a migration (not a seeder) so it exists in every
 * environment, including production, before anyone is granted it (`php artisan users:make-admin`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::findOrCreate(User::ADMIN_ROLE, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', User::ADMIN_ROLE)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
