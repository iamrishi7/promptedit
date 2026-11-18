<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.key' => 'pf_test_key',
    ]);
    Http::preventStrayRequests();
    $this->travelTo(now('UTC')->setDate(2026, 11, 18)->setTime(12, 0));

    $this->admin = User::factory()->create(['name' => 'Ops']);
    $this->admin->assignRole(User::ADMIN_ROLE);
    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);

    // Docs example: Pixelforge cost $0.30551, charge $0.3818875 (cost × 1.25).
    $billed = fn (User $user, string $completedAt, int $cost = 305_510_000, int $charge = 381_887_500) => Generation::factory()->for($user)->succeeded()->create([
        'completed_at' => $completedAt,
        'provider_cost_nanos' => $cost,
        'charge_nanos' => $charge,
    ]);

    $billed($this->alice, '2026-11-18 09:00:00');
    $billed($this->alice, '2026-11-18 10:00:00', 12_167_500, 15_209_375);
    $billed($this->bob, '2026-11-18 23:59:59');               // last second of the 18th (UTC)
    $billed($this->bob, '2026-11-17 00:00:00');               // first second of the 17th
    Generation::factory()->for($this->alice)->failed()->create(['completed_at' => '2026-11-18 11:00:00']);
    Generation::factory()->for($this->alice)->processing()->create();
    $billed($this->alice, '2026-11-04 12:00:00');              // outside the default 14 days

    $this->usage = fn (array $days) => Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => $days,
    ])]);
    $this->day = fn (string $date, int $generations, string $cost) => [
        'date' => $date, 'generations' => $generations, 'input_text_tokens' => 0, 'input_image_tokens' => 0,
        'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost,
    ];
});

it('is only for admins', function () {
    $this->get(route('admin.spend'))->assertRedirect(route('login'));
    $this->actingAs($this->alice)->get(route('admin.spend'))->assertForbidden();
});

it('totals billed generations per UTC day and per user, exactly', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.spend'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/SpendReport')
            ->where('range', ['from' => '2026-11-05', 'to' => '2026-11-18', 'max_days' => 92])
            ->has('report.days', 14)
            // The 18th: Alice 2 (one cheap), Bob 1 at 23:59:59. The failed and in-flight ones aren't billed.
            ->where('report.days.0.date', '2026-11-18')
            ->where('report.days.0.generations', 3)
            ->where('report.days.0.provider_cost', ['nanos' => '623187500', 'display' => '$0.6231875'])
            ->where('report.days.0.charge', ['nanos' => '778984375', 'display' => '$0.778984375'])
            ->where('report.days.0.profit', ['nanos' => '155796875', 'display' => '$0.155796875'])
            ->where('report.days.0.margin', '20.00%')
            ->where('report.days.2.margin', null)
            ->where('report.days.0.users.0.name', 'Alice')
            ->where('report.days.0.users.0.generations', 2)
            ->where('report.days.0.users.0.charge.nanos', '397096875')
            ->where('report.days.0.users.1.name', 'Bob')
            ->where('report.days.0.users.1.profit.nanos', '76377500')
            ->where('report.days.1.date', '2026-11-17')
            ->where('report.days.1.generations', 1)
            ->where('report.days.2.generations', 0)
            ->where('report.totals.generations', 4)
            ->where('report.totals.provider_cost.nanos', '928697500')
            ->where('report.totals.charge.nanos', '1160871875')
            ->where('report.totals.profit.nanos', '232174375')
            ->where('report.totals.margin', '20.00%')
            ->where('report.days.0.users.0.margin', '20.00%'));
});

it('shows a day matching Pixelforge to the nanodollar, and flags a day that differs', function () {
    ($this->usage)([
        ($this->day)('2026-11-18', 3, '0.623187500'),   // matches
        ($this->day)('2026-11-17', 1, '0.305510001'),   // Pixelforge billed one nanodollar more
        ...array_map(fn (int $d) => ($this->day)(sprintf('2026-11-%02d', $d), 0, '0.000000000'), range(5, 16)),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.spend'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('pixelforge')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('pixelforge.available', true)
                ->where('pixelforge.matches', false)
                ->where('pixelforge.days.2026-11-18.matches', true)
                ->where('pixelforge.days.2026-11-18.difference', null)
                ->where('pixelforge.days.2026-11-17.matches', false)
                ->where('pixelforge.days.2026-11-17.difference', ['amount' => ['nanos' => '1', 'display' => '$0.000000001'], 'we_recorded' => 'less'])
                ->where('pixelforge.days.2026-11-10.matches', true)));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-11-05') && str_contains($request->url(), 'end_date=2026-11-18'));
});

it('still shows the report when Pixelforge is unavailable', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    $this->actingAs($this->admin)
        ->get(route('admin.spend'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.generations', 4)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('pixelforge.available', false)
                ->has('pixelforge.message')));
});

it('reports any range of up to 92 UTC days', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.spend', ['from' => '2026-11-04', 'to' => '2026-11-04']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.days', 1)
            ->where('report.totals.generations', 1));
});

it('refuses a range that is too long, reversed or in the future', function (array $query, string $field) {
    $this->actingAs($this->admin)
        ->from(route('admin.spend'))
        ->get(route('admin.spend', $query))
        ->assertSessionHasErrors($field);
})->with([
    'more than 92 days' => [['from' => '2026-08-01', 'to' => '2026-11-18'], 'from'],
    'reversed' => [['from' => '2026-11-10', 'to' => '2026-11-01'], 'from'],
    'future' => [['from' => '2026-11-18', 'to' => '2026-11-19'], 'to'],
    'not a date' => [['from' => '18/11/2026'], 'from'],
]);

it('tells the pages who is an admin', function () {
    $this->actingAs($this->admin)->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', true));
    $this->actingAs($this->alice)->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', false));
});

it('grants and revokes the admin role from the command line', function () {
    $this->artisan('users:make-admin', ['email' => $this->bob->email])->assertSuccessful();
    expect($this->bob->fresh()->hasRole(User::ADMIN_ROLE))->toBeTrue();

    $this->artisan('users:make-admin', ['email' => $this->bob->email, '--revoke' => true])->assertSuccessful();
    expect($this->bob->fresh()->hasRole(User::ADMIN_ROLE))->toBeFalse();

    $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

it('works with a cache that serializes, as Redis does in production', function () {
    config(['cache.stores.array.serialize' => true]);
    app('cache')->forgetDriver('array');
    ($this->usage)([($this->day)('2026-11-18', 3, '0.623187500')]);

    foreach ([1, 2] as $visit) { // the second visit reads Pixelforge's figures back from the cache
        $this->actingAs($this->admin)
            ->get(route('admin.spend', ['from' => '2026-11-18', 'to' => '2026-11-18']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('pixelforge.available', true)
                ->where('pixelforge.days.2026-11-18.matches', true)));
    }

    Http::assertSentCount(1);
});
