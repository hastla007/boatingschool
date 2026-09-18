<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    public function index(): View
    {
        $products = Product::orderBy('name')->get();

        return view('superadmin.products.index', ['products' => $products]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:product,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Product::create($validated + ['active' => true]);

        return redirect()->route('superadmin.products.index')->with('status', 'Produkt angelegt.');
    }

    public function toggleActive(Product $product): Response
    {
        $product->update(['active' => ! $product->active]);

        return back()->with('status', $product->active ? 'Produkt aktiviert.' : 'Produkt deaktiviert.');
    }
}
