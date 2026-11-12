<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\TipContent;
use App\Http\Controllers\Controller;
use App\Models\TipCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TipsController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = TipCategory::query()
            ->with(['tips' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('title')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (TipCategory $category) => $category->tips->isNotEmpty())
            ->values();

        $count = 0;
        $groups = $categories->map(function (TipCategory $category) use (&$count): array {
            $tips = $category->tips->map(function ($tip) use (&$count): array {
                $count++;
                $content = TipContent::present((string) $tip->body);

                return [
                    'id' => (string) $tip->id,
                    'title' => html_entity_decode((string) $tip->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'html' => $content['html'],
                    'text' => $content['text'],
                    'links' => $content['links'],
                ];
            })->values();

            return [
                'id' => (string) $category->id,
                'slug' => Str::slug($category->name),
                'name' => (string) $category->name,
                'tips' => $tips,
            ];
        })->values();

        return response()->json([
            'data' => $groups,
            'meta' => ['groups' => $groups->count(), 'tips' => $count],
        ]);
    }
}
