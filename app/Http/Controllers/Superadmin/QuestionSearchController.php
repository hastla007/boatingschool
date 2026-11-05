<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ContentQuestion;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Fragen über alle Module suchen (Fragetext, Antworten, Erklärung, Content-ID, amtliche Nummer). */
class QuestionSearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $q = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $results = collect();

        if (mb_strlen($q) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $results = ContentQuestion::query()
                ->where(function ($query) use ($like, $q) {
                    $query->where('content_id', 'ilike', $like)
                        ->orWhere('official_number', $q)
                        ->orWhereHas('revisions', fn ($r) => $r->whereIn('editorial_status', ['published', 'review'])
                            ->where(fn ($t) => $t->where('question_text', 'ilike', $like)
                                ->orWhere('feedback_correct', 'ilike', $like)
                                ->orWhere('feedback_incorrect', 'ilike', $like)
                                ->orWhereHas('answers', fn ($a) => $a->where('answer_text', 'ilike', $like))));
                })
                ->with(['modules:id,name,code', 'revisions' => fn ($r) => $r->whereIn('editorial_status', ['published', 'review'])->orderByDesc('revision_no')])
                ->orderBy('content_id')
                ->limit(100)
                ->get();
        }

        return view('superadmin.questions.search', ['q' => $q, 'results' => $results]);
    }
}
