<?php

namespace App\Imports;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/** Columns: name, email, phone, role_code, department_code. Rows are validated one by one; bad rows are reported, not fatal. */
class UsersImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public array $errors = [];

    public function __construct(private OrganizationContext $ctx) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $v = Validator::make($row->toArray(), [
                'name' => 'required|string|max:150', 'email' => 'required|email|unique:users,email', 'role_code' => 'required|string',
            ]);
            if ($v->fails()) { $this->errors[] = ['line' => $line, 'message' => $v->errors()->first()]; continue; }

            $role = Role::where('code', $row['role_code'])->where('is_active', true)->first();
            if (!$role || $role->level > $this->ctx->level()) { $this->errors[] = ['line' => $line, 'message' => 'role']; continue; }

            $dept = !empty($row['department_code']) ? Department::where('code', $row['department_code'])->first() : null;

            $user = User::create([
                'name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone'] ?? null,
                'password' => Str::random(40), 'must_change_password' => true,
                'status_id' => Lookup::id('user_status', 'invited'),
            ]);
            $m = Membership::create([
                'organization_id' => $this->ctx->organizationId(), 'user_id' => $user->id, 'department_id' => $dept?->id,
                'status_id' => Lookup::id('membership_status', 'active'), 'is_default' => true,
            ]);
            $m->roles()->attach($role->id, ['assigned_by' => request()->user()->id]);
            try { Password::sendResetLink(['email' => $user->email]); } catch (\Throwable $e) { report($e); }
            $this->created++;
        }
    }
}
