<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PeopleCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'People and data';
    }

    public function run(): array
    {
        $rows = [];
        $s = 'People and data';

        $people = User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin->value, UserRole::Supervisor->value])->orderBy('id')->get(['name', 'role']);
        $rows[] = DoctorRow::by($people->contains(fn ($u) => $u->role === UserRole::Admin), 'warn', $s, 'active admins and supervisors',
            $people->map(fn ($u) => $u->name.' ('.($u->role?->value ?? $u->role).')')->implode(', '), 'No active admin: nobody can Run or Stop campaigns and ad sets.');

        $overlap = DB::table('ad_account_assignments')->whereNull('ends_on')->select('ad_account_id')->groupBy('ad_account_id')->havingRaw('COUNT(*) > 1')->pluck('ad_account_id')->all();
        $rows[] = DoctorRow::by($overlap === [], 'warn', $s, 'overlapping open assignments', $overlap === [] ? '0' : 'accounts '.implode(', ', $overlap), 'More than one open buyer assignment on one account (F-054): the report credits the wrong buyer.');

        $version = DB::connection()->getDriverName() === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v';
        $rows[] = DoctorRow::ok($s, 'database version', (string) (DB::selectOne($version)->v ?? '?'));

        return $rows;
    }
}
