<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
            'sellable_standalone' => ['nullable', 'boolean'],
        ]);

        Product::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'active' => true,
            // Ohne Angabe (z. B. ältere Formulare): allein verkäuflich.
            'sellable_standalone' => $request->has('sellable_standalone') ? $request->boolean('sellable_standalone') : true,
        ]);

        return redirect()->route('superadmin.products.index')->with('status', 'Produkt angelegt.');
    }

    /**
     * Name und „allein verkäuflich“ ändern; der Code bleibt (Coupons und
     * Importe verweisen darauf).
     */
    public function update(Request $request, Product $product): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sellable_standalone' => ['nullable', 'boolean'],
        ]);
        if ($request->has('sellable_standalone')) {
            $validated['sellable_standalone'] = $request->boolean('sellable_standalone');
        }
        $before = $product->only('name', 'sellable_standalone');
        $product->update($validated);
        AuditLog::record(null, $request->user()->id, 'superadmin.product.update', 'product', $product->id, $before, $validated);

        return back()->with('status', 'Produkt gespeichert.');
    }

    public function toggleActive(Product $product): Response
    {
        $product->update(['active' => ! $product->active]);

        return back()->with('status', $product->active ? 'Produkt aktiviert.' : 'Produkt deaktiviert.');
    }
}
