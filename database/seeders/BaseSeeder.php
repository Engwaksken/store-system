<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\CostCode;
use App\Models\ExpenseCategory;
use App\Models\InventoryCategory;
use App\Models\InventoryUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Production-safe reference data plus the first admin account.
 * Admin credentials come from ADMIN_EMAIL / ADMIN_PASSWORD in .env; the password must be changed at first login.
 */
class BaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Cement & aggregates', 'Steel & reinforcement', 'Timber', 'Blocks & bricks', 'Plumbing', 'Electrical', 'Roofing', 'Paint & finishes', 'Hardware & fixings', 'Tools & consumables'] as $c) {
            InventoryCategory::firstOrCreate(['name' => $c]);
        }
        foreach ([['Bag', 'bag'], ['Piece', 'pc'], ['Metre', 'm'], ['Kilogram', 'kg'], ['Tonne', 't'], ['Litre', 'L'], ['Sheet', 'sht'], ['Cubic metre', 'm3'], ['Roll', 'roll'], ['Box', 'box'], ['Trip (lorry)', 'trip']] as [$n, $a]) {
            InventoryUnit::firstOrCreate(['abbreviation' => $a], ['name' => $n]);
        }
        foreach (['Casual labour', 'Transport & fuel', 'Equipment hire', 'Direct site purchase', 'Permits & fees', 'Utilities', 'Meals & allowances', 'Miscellaneous'] as $c) {
            ExpenseCategory::firstOrCreate(['name' => $c]);
        }
        foreach ([
            ['01-PRE', 'Preliminaries & site setup', 'overheads'], ['02-SUB', 'Substructure (excavation, foundations)', 'materials'],
            ['03-CON', 'Concrete & reinforcement', 'materials'], ['04-WAL', 'Walling & blockwork', 'materials'],
            ['05-ROF', 'Roofing', 'materials'], ['06-FIN', 'Finishes', 'materials'], ['07-MEP', 'Plumbing & electrical', 'subcontract'],
            ['08-LAB', 'Labour', 'labour'], ['09-EQP', 'Equipment & transport', 'equipment'],
        ] as [$code, $name, $cat]) {
            CostCode::firstOrCreate(['code' => $code], ['name' => $name, 'category' => $cat]);
        }
        if (! AppSetting::where('key', 'company_name')->exists()) {
            AppSetting::put('company_name', config('cms.company_name'));
        }

        if (! User::where('role', Role::Admin)->exists()) {
            $email = config('cms.admin_email');
            $password = config('cms.admin_password') ?: Str::password(14, symbols: false);
            User::create([
                'name' => 'System Administrator',
                'email' => $email,
                'password' => $password,
                'role' => Role::Admin,
                'must_change_password' => true,
            ]);
            $this->command?->warn("Admin created: {$email}".(config('cms.admin_password') ? '' : " / temporary password: {$password}"));
        }
    }
}
