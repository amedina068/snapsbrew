<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TemplateSeeder extends Seeder
{
    public function run()
    {
        // Seed preset templates from SQL files (if available)
        $templateFiles = [
            'blank-1.txt',
            'instagram-gradient-1.txt',
            'instagram-basic-1.txt',
            'instagram-story-basic-1.txt',
            'instagram-story-gradient-1.txt',
            'certificate-1.txt',
            'certificate-2.txt',
            'onboarding-showcase.txt',
            'onboarding-social-post.txt',
            'onboarding-social-story.txt',
            'onboarding-blog-og.txt',
            'onboarding-certificate.txt',
            'onboarding-marketing-banner.txt',
        ];

        collect($templateFiles)->each(function ($statement) {
            if (Storage::disk('local')->exists("templates/{$statement}")) {
                DB::insert(Storage::disk('local')->get("templates/{$statement}"));
            }
        });

        // Seed default size presets
        collect(Template::DEFAULT_SIZES)->each(function ($size) {
            Template::query()->firstOrCreate(
                [
                    'title' => "[Preset] {$size['name']}",
                ],
                [
                    'width' => $size['width'],
                    'height' => $size['height'],
                    'size' => $size['value'],
                    'layers' => json_encode([]),
                ]
            );
        });

        // Create user-owned templates for each company
        User::whereNotNull('company_id')->get()->each(function (User $user) {
            Template::factory()
                ->count(rand(2, 4))
                ->create([
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                ]);
        });
    }
}
