<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Present;
use App\Api\TipContent;
use App\Http\Controllers\Controller;
use App\Models\Tip;
use App\Models\TipCategory;
use App\Support\ProtectedMedia;
use App\Support\TipPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Tipps und Tricks (plattformweit, dieselbe Auswahl wie TipController im Web). */
class TipsController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = TipCategory::query()
            ->with(['tips' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('title'), 'tips.pdfAsset'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (TipCategory $category) => $category->tips->isNotEmpty())
            ->values();

        $count = 0;
        $groups = $categories->map(function (TipCategory $category) use (&$count): array {
            $tips = $category->tips->map(function ($tip) use (&$count): array {
                $count++;
                // Signierte, kurzlebige Adresse (TipPdf); nie zwischenspeichern.
                $pdfUrl = Present::url(TipPdf::url($tip));
                $content = TipContent::present((string) $tip->body, $pdfUrl ? $this->oldPdfLinks($tip, $pdfUrl) : []);

                return [
                    'id' => (string) $tip->id,
                    'title' => html_entity_decode((string) $tip->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'html' => $content['html'],
                    'text' => $content['text'],
                    'links' => $content['links'],
                    'pdf' => $pdfUrl ? [
                        'label' => html_entity_decode((string) $tip->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'url' => $pdfUrl,
                        'mime_type' => 'application/pdf',
                    ] : null,
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

    /**
     * Frühere öffentliche Adressen des PDFs (/storage/...), falls die
     * Redaktion sie in den Text kopiert hat: auf die signierte umstellen.
     *
     * @return array<string, string>
     */
    private function oldPdfLinks(Tip $tip, string $pdfUrl): array
    {
        $path = ProtectedMedia::relativePath($tip->pdfAsset?->getRawOriginal('storage_path'));
        if ($path === null) {
            return [];
        }

        return [
            Storage::disk('public')->url($path) => $pdfUrl,
            url('/storage/'.$path) => $pdfUrl,
            Present::url('/storage/'.$path) => $pdfUrl,
        ];
    }
}
