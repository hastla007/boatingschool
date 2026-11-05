<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use App\Models\Product;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Superadmin: Produkte umbenennen, Module bearbeiten/sortieren, Fragensuche, Navigationstexte. */
class SuperadminContentEditorsTest extends TestCase
{
    use InteractsWithTenants;

    private array $cleanup = ['courses' => [], 'modules' => [], 'questions' => [], 'products' => []];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->cleanup['courses'])->delete();
            Module::whereIn('id', $this->cleanup['modules'])->delete();
            ContentQuestion::whereIn('id', $this->cleanup['questions'])->delete();
            Product::whereIn('id', $this->cleanup['products'])->delete();
        });
        parent::tearDown();
    }

    private function course(string $name = 'Editor-Kurs'): CourseDefinition
    {
        return $this->onAdmin(function () use ($name) {
            $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'TEST-ED-'.uniqid(), 'name' => $name, 'course_type' => 'full', 'status' => 'published']);
            $this->cleanup['courses'][] = $course->id;

            return $course;
        });
    }

    public function test_products_are_renamed_and_modules_edited_and_sorted(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$product, $m1, $m2, $course] = $this->onAdmin(function () {
            $product = Product::create(['code' => 'TEST-P-'.uniqid(), 'name' => 'Alt', 'active' => true]);
            $m1 = Module::create(['code' => 'TEST_M1_'.uniqid(), 'name' => 'Erstes', 'version' => '1', 'status' => 'published']);
            $m2 = Module::create(['code' => 'TEST_M2_'.uniqid(), 'name' => 'Zweites', 'version' => '1', 'status' => 'published']);
            $this->cleanup['products'][] = $product->id;
            array_push($this->cleanup['modules'], $m1->id, $m2->id);

            return [$product, $m1, $m2, null];
        });
        $course = $this->course();
        $this->onAdmin(fn () => $course->modules()->attach([$m1->id => ['sort_order' => 1, 'required' => true], $m2->id => ['sort_order' => 2, 'required' => true]]));

        $this->actingAs($superadmin)->patch("/superadmin/products/{$product->id}", ['name' => 'Fahrstunde 45 Min'])->assertSessionHasNoErrors();
        $this->assertSame('Fahrstunde 45 Min', $this->onAdmin(fn () => $product->fresh()->name));

        $this->actingAs($superadmin)->patch("/superadmin/modules/{$m1->id}", ['name' => 'Basisfragen', 'version' => '2026-10', 'description' => 'Quelle: ELWIS'])->assertSessionHasNoErrors();
        $m1 = $this->onAdmin(fn () => $m1->fresh());
        $this->assertSame(['Basisfragen', '2026-10', 'Quelle: ELWIS'], [$m1->name, $m1->version, $m1->description]);
        $this->actingAs($superadmin)->get("/superadmin/modules/{$m1->id}/questions")->assertSee('Quelle: ELWIS');

        $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/modules/{$m2->id}/move", ['direction' => 'up']);
        $this->assertSame([$m2->id, $m1->id], $this->onAdmin(fn () => $course->modules()->pluck('module.id')->all()));
    }

    public function test_questions_are_found_across_modules(): void
    {
        $superadmin = $this->createSuperAdmin();
        $marker = 'Leuchtfeuer'.uniqid();
        $question = $this->onAdmin(function () use ($marker) {
            $module = Module::create(['code' => 'TEST_SM_'.uniqid(), 'name' => 'Suchmodul', 'version' => '1', 'status' => 'published']);
            $q = ContentQuestion::create(['content_id' => 'TEST-SEARCH-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $module->questions()->attach($q->id, ['required' => true]);
            ContentQuestionRevision::create(['question_id' => $q->id, 'revision_no' => 1, 'question_type' => 'single_choice', 'question_text' => "Was zeigt ein {$marker}?", 'editorial_status' => 'published', 'rights_status' => 'licensed']);
            $this->cleanup['modules'][] = $module->id;
            $this->cleanup['questions'][] = $q->id;

            return $q;
        });

        $this->actingAs($superadmin)->get('/superadmin/questions/search?q='.strtolower($marker))->assertOk()
            ->assertSee("Was zeigt ein {$marker}?")->assertSee('Suchmodul')->assertSee(route('superadmin.questions.edit', $question));
        $this->actingAs($superadmin)->get('/superadmin/questions/search?q='.$question->content_id)->assertSee("Was zeigt ein {$marker}?");
        $this->actingAs($superadmin)->get('/superadmin/questions/search?q=x')->assertOk()->assertSee('mindestens 2 Zeichen');
        $this->actingAs($superadmin)->get('/superadmin/questions/search?q=%25')->assertOk();
    }

    public function test_navigation_texts_are_edited_and_copied_to_combo_courses(): void
    {
        $superadmin = $this->createSuperAdmin();
        $source = $this->course('Quelle');
        $combo = $this->course('Kombi');
        [$task, $copy] = $this->onAdmin(function () use ($source, $combo) {
            $make = function ($course) {
                $t = NavigationTask::create(['course_id' => $course->id, 'task_number' => 7, 'scenario_text' => 'Altes Szenario', 'sort_order' => 1]);
                NavigationQuestion::create(['navigation_task_id' => $t->id, 'question_number' => 1, 'question_text' => 'Kurs?', 'answer_text' => 'rwK = 090°', 'sort_order' => 1]);
                NavigationQuestion::create(['navigation_task_id' => $t->id, 'question_number' => 2, 'question_text' => 'Weg?', 'answer_text' => '5 sm', 'sort_order' => 2]);

                return $t;
            };

            return [$make($source), $make($combo)];
        });
        $questions = $this->onAdmin(fn () => $task->questions()->get());

        $this->actingAs($superadmin)->get("/superadmin/navigation-tasks/{$task->id}/edit")->assertOk()->assertSee('Auch in 1 Kopie(n) übernehmen (Kombi)');
        $this->actingAs($superadmin)->patch("/superadmin/navigation-tasks/{$task->id}", [
            'scenario_text' => 'Neues Szenario', 'hint' => 'Tipp', 'apply_to_copies' => '1',
            'questions' => [
                ['id' => $questions[0]->id, 'question_text' => 'Wie lautet der rwK?', 'answer_text' => 'rwK = 091°'],
                ['id' => $questions[1]->id, 'question_text' => 'Weg?', 'answer_text' => '5 sm', 'delete' => '1'],
                ['question_text' => 'Neue Frage?', 'answer_text' => '12:30 Uhr'],
            ],
        ])->assertSessionHasNoErrors();

        foreach ([$task, $copy] as $t) {
            $fresh = $this->onAdmin(fn () => NavigationTask::with('questions')->find($t->id));
            $this->assertSame('Neues Szenario', $fresh->scenario_text);
            $this->assertSame(['Wie lautet der rwK?', 'Neue Frage?'], $fresh->questions->pluck('question_text')->all());
            $this->assertSame(['rwK = 091°', '12:30 Uhr'], $fresh->questions->pluck('answer_text')->all());
            $this->assertSame([1, 2], $fresh->questions->pluck('question_number')->all());
        }

        $this->actingAs($superadmin)->patch("/superadmin/navigation-tasks/{$task->id}", ['scenario_text' => 'X', 'questions' => [['question_text' => 'Nur Frage', 'answer_text' => '']]])
            ->assertSessionHasErrors('questions');
    }
}
