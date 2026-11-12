<?php

namespace App\Console\Commands;

use App\Models\CustomerFaq;
use App\Models\CustomerFaqCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportMobileFaqs extends Command
{
    protected $signature = 'mobile-api:import-faqs {path : JSON export containing categories and FAQs}';

    protected $description = 'Imports or updates native mobile API FAQs from a stable-keyed JSON export.';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Datei fehlt oder ist nicht lesbar: {$path}");

            return self::FAILURE;
        }

        $document = json_decode((string) file_get_contents($path), true);
        if (! is_array($document) || ! is_array($document['categories'] ?? null) || ! is_array($document['faqs'] ?? null)) {
            $this->error('JSON muss die Arrays "categories" und "faqs" enthalten.');

            return self::FAILURE;
        }

        $errors = [];
        $categoryKeys = [];
        $faqKeys = [];
        foreach ($document['categories'] as $index => $category) {
            if (! is_array($category)) {
                $errors[] = 'categories['.$index.']: Eintrag muss ein JSON-Objekt sein.';
                continue;
            }
            $validator = Validator::make($category, [
                'source_key' => ['required', 'string', 'max:191'],
                'course_code' => ['required', 'string', 'max:100'],
                'name' => ['required', 'string', 'max:191'],
                'slug' => ['required', 'string', 'max:191'],
                'parent_source_key' => ['nullable', 'string', 'max:191'],
                'sort_order' => ['nullable', 'integer'],
            ]);
            if ($validator->fails()) {
                $errors[] = 'categories['.$index.']: '.$validator->errors()->first();
            } elseif (isset($categoryKeys[$category['source_key']])) {
                $errors[] = 'categories['.$index.']: source_key kommt mehrfach vor.';
            } else {
                $categoryKeys[$category['source_key']] = true;
            }
        }
        foreach ($document['faqs'] as $index => $faq) {
            if (! is_array($faq)) {
                $errors[] = 'faqs['.$index.']: Eintrag muss ein JSON-Objekt sein.';
                continue;
            }
            $validator = Validator::make($faq, [
                'source_key' => ['required', 'string', 'max:191'],
                'question' => ['required', 'string', 'max:500'],
                'answer' => ['required', 'string'],
                'status' => ['nullable', 'in:draft,published,archived'],
                'sort_order' => ['nullable', 'integer'],
                'category_source_keys' => ['required', 'array', 'min:1'],
                'category_source_keys.*' => ['required', 'string', 'max:191'],
            ]);
            if ($validator->fails()) {
                $errors[] = 'faqs['.$index.']: '.$validator->errors()->first();
            } elseif (isset($faqKeys[$faq['source_key']])) {
                $errors[] = 'faqs['.$index.']: source_key kommt mehrfach vor.';
            } else {
                $faqKeys[$faq['source_key']] = true;
            }
        }
        foreach ($document['categories'] as $index => $category) {
            if (is_array($category) && ! empty($category['parent_source_key'])
                && ! isset($categoryKeys[$category['parent_source_key']])) {
                $errors[] = 'categories['.$index.']: parent_source_key verweist auf keine importierte Kategorie.';
            }
        }
        foreach ($document['faqs'] as $index => $faq) {
            if (! is_array($faq) || ! is_array($faq['category_source_keys'] ?? null)) {
                continue;
            }
            foreach ($faq['category_source_keys'] as $key) {
                if (! isset($categoryKeys[$key])) {
                    $errors[] = 'faqs['.$index.']: category_source_keys verweist auf keine importierte Kategorie.';
                    break;
                }
            }
        }
        if ($errors !== []) {
            $this->error('Import abgebrochen; die Datei enthält ungültige Einträge:');
            foreach ($errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        // Content imports use the owner connection; runtime roles are deliberately restricted.
        config(['database.default' => 'pgsql_admin']);
        try {
            DB::transaction(function () use ($document): void {
                $categories = [];
                foreach ($document['categories'] as $row) {
                    $categories[$row['source_key']] = CustomerFaqCategory::query()->updateOrCreate(
                        ['source_key' => $row['source_key']],
                        [
                            'course_code' => $row['course_code'],
                            'parent_id' => null,
                            'name' => $row['name'],
                            'slug' => $row['slug'],
                            'sort_order' => (int) ($row['sort_order'] ?? 0),
                        ]
                    );
                }
                foreach ($document['categories'] as $row) {
                    $category = $categories[$row['source_key']];
                    $parentKey = $row['parent_source_key'] ?? null;
                    if ($parentKey !== null && $categories[$parentKey]->course_code !== $category->course_code) {
                        throw new \RuntimeException("Übergeordnete Kategorie {$parentKey} gehört zu einem anderen Kurs.");
                    }
                    $category->parent_id = $parentKey === null ? null : $categories[$parentKey]->id;
                    $category->save();
                }

                foreach ($document['faqs'] as $row) {
                    $faq = CustomerFaq::query()->updateOrCreate(
                        ['source_key' => $row['source_key']],
                        [
                            'question' => $row['question'],
                            'answer' => $row['answer'],
                            'status' => $row['status'] ?? 'published',
                            'sort_order' => (int) ($row['sort_order'] ?? 0),
                        ]
                    );
                    $categoryIds = [];
                    foreach ($row['category_source_keys'] as $key) {
                        $categoryIds[$categories[$key]->id] = ['sort_order' => (int) ($row['sort_order'] ?? 0)];
                    }
                    $faq->categories()->sync($categoryIds);
                }
            });
        } catch (\Throwable $exception) {
            $this->error('Import abgebrochen: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(count($document['categories']).' Kategorien und '.count($document['faqs']).' FAQs importiert/aktualisiert.');

        return self::SUCCESS;
    }
}
