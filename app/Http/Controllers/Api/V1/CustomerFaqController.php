<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\CourseAccess;
use App\Api\FaqAnswer;
use App\Http\Controllers\Controller;
use App\Models\CustomerFaqCategory;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Published customer FAQs for one course, in the same category hierarchy as WP. */
class CustomerFaqController extends Controller
{
    private const RELATED_COURSES = [
        'SRC-UBI' => ['SRC', 'UBI'],
        'SBF-SEE-BIN' => ['SBF-SEE', 'SBF-BIN-MOTOR'],
        'ADD-UBI-TO-SRC' => ['UBI'],
        'ADD-BIN-TO-SEE' => ['SBF-SEE'],
        'ADD-SEE-TO-BIN' => ['SBF-BIN-MOTOR'],
        'ADD-BIN-SEGEL' => ['SBF-BIN-SEGEL'],
        'ADD-FKN-TO-SKN' => ['SKN'],
    ];

    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $course = $this->access->require($this->tenantContext->tenant(), $request->user(), $courseId);
        $keys = array_values(array_unique(array_merge([$course->code], self::RELATED_COURSES[$course->code] ?? [])));
        $categories = CustomerFaqCategory::whereIn('course_code', $keys)
            ->orderBy('sort_order')->orderBy('name')->with('faqs')->get();
        $roots = $categories->whereNull('parent_id')->sortBy(
            fn (CustomerFaqCategory $category) => [
                array_search($category->course_code, $keys, true),
                $category->sort_order,
                $category->name,
            ]
        );
        $children = $categories->whereNotNull('parent_id')->groupBy('parent_id');
        $seen = [];
        $count = 0;
        $data = [];
        foreach ($roots as $root) {
            $direct = $this->faqPayloads($root, $seen, $count);
            $subcategories = [];
            $this->appendChildren($root, 1, $children, $subcategories, $seen, $count);
            if ($direct === [] && $subcategories === []) {
                continue;
            }
            $data[] = [
                'id' => $root->id,
                'key' => $root->course_code,
                'name' => $root->name,
                'slug' => $root->slug,
                'faqs' => $direct,
                'subcategories' => $subcategories,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => ['course_id' => $course->id, 'groups' => count($data), 'faqs' => $count],
        ]);
    }

    private function appendChildren(CustomerFaqCategory $parent, int $depth, Collection $children, array &$out, array &$seen, int &$count): bool
    {
        if ($depth > 10) {
            return false;
        }
        $hasAny = false;
        foreach ($children->get($parent->id, collect())->sortBy(fn ($category) => [$category->sort_order, $category->name]) as $child) {
            $faqs = $this->faqPayloads($child, $seen, $count);
            $nested = [];
            $below = $this->appendChildren($child, $depth + 1, $children, $nested, $seen, $count);
            if ($faqs === [] && ! $below) {
                continue;
            }
            $out[] = [
                'id' => $child->id,
                'name' => $child->name,
                'slug' => $child->slug,
                'parent_id' => $parent->id,
                'depth' => $depth,
                'faqs' => $faqs,
            ];
            $out = array_merge($out, $nested);
            $hasAny = true;
        }

        return $hasAny;
    }

    private function faqPayloads(CustomerFaqCategory $category, array &$seen, int &$count): array
    {
        $items = [];
        foreach ($category->faqs as $faq) {
            if (isset($seen[$faq->id])) {
                continue;
            }
            $seen[$faq->id] = true;
            $count++;
            $answer = FaqAnswer::present((string) $faq->answer);
            $items[] = [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer_html' => $answer['html'],
                'answer_text' => $answer['text'],
                'links' => $answer['links'],
            ];
        }

        return $items;
    }
}
