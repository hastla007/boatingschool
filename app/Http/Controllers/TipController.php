<?php

namespace App\Http\Controllers;

use App\Models\TipCategory;
use Illuminate\View\View;

class TipController extends Controller
{
    public function index(): View
    {
        $categories = TipCategory::with(['tips' => function ($query) {
            $query->where('active', true)->orderBy('sort_order')->orderBy('title');
        }])->orderBy('sort_order')->orderBy('name')->get()->filter(fn (TipCategory $category) => $category->tips->isNotEmpty());

        return view('tips.index', ['categories' => $categories]);
    }
}
