<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'personal' => ['identity', 'invoices', 'contracts', 'insurance', 'medical', 'car', 'home', 'education', 'warranties', 'banking', 'tax', 'pensions', 'travel', 'subscriptions', 'legal', 'other'],
            'business' => ['invoices', 'contracts', 'tax', 'accounting', 'hr', 'company', 'purchases', 'insurance', 'banking', 'sales', 'assets', 'legal', 'certifications', 'other'],
        ] as $scope => $keys) {
            foreach ($keys as $key) {
                Category::unguarded(fn () => Category::firstOrCreate(['workspace_id' => null, 'scope' => $scope, 'system_key' => $key]));
            }
        }
    }
}
