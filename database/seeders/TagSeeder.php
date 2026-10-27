<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['important', 'urgent', 'recurring', 'paid', 'to_review', 'signed', 'original', 'archive'] as $key) {
            Tag::unguarded(fn () => Tag::firstOrCreate(['system_key' => $key], ['workspace_id' => null, 'name' => $key, 'normalized_name' => $key]));
        }
    }
}
