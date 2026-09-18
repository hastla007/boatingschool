<?php

namespace App\Http\Controllers;

use App\Models\Tip;
use Illuminate\View\View;

class TipController extends Controller
{
    public function index(): View
    {
        $tips = Tip::where('active', true)
            ->orderBy('category')->orderBy('sort_order')->orderBy('title')
            ->get()
            ->groupBy('category');

        return view('tips.index', ['tips' => $tips]);
    }
}
