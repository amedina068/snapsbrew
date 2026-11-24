<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));

        $tags = Tag::query()
            ->where('company_id', Auth::user()->company_id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'data' => $tags,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($request->name);
        $slugBase = Str::slug($name);
        $slug = $slugBase !== '' ? $slugBase : Str::slug(Str::random(8));
        $companyId = Auth::user()->company_id;

        $existing = false;

        if ($slugBase !== '') {
            $existing = Tag::query()
                ->where('company_id', $companyId)
                ->where('slug', $slug)
                ->exists();

            $counter = 1;
            while ($existing) {
                $candidate = "{$slugBase}-{$counter}";
                $existing = Tag::query()
                    ->where('company_id', $companyId)
                    ->where('slug', $candidate)
                    ->exists();
                if (! $existing) {
                    $slug = $candidate;
                    break;
                }
                $counter++;
            }
        }

        $tag = Tag::create([
            'company_id' => $companyId,
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'data' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ],
        ], 201);
    }
}
