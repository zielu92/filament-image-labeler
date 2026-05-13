<?php

namespace Zielu92\FilamentImageLabeler\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\FilamentImageLabelerServiceProvider;
use Zielu92\FilamentImageLabeler\Models\Annotation;

class HasAnnotationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create the annotations table
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->string('annotatable_type');
            $table->unsignedBigInteger('annotatable_id');
            $table->string('annotation_id');
            $table->json('geometry');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['annotatable_type', 'annotatable_id']);
            $table->unique(['annotatable_type', 'annotatable_id', 'annotation_id']);
        });

        // Create a test table for the annotatable model
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_models');
        Schema::dropIfExists('annotations');
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            FilamentImageLabelerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    public function test_sync_annotations_creates_from_empty(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
                'metadata' => ['title' => 'Person A', 'category' => 'person'],
            ],
            [
                'annotation_id' => 'ann-2',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:200,100,300,200']],
                'metadata' => ['title' => 'Building', 'category' => 'object'],
            ],
        ]);

        $model->refresh();

        $this->assertCount(2, $model->annotations);
        $ann1 = $model->annotations->firstWhere('annotation_id', 'ann-1');
        $this->assertEquals('Person A', $ann1->metadata['title']);
        $this->assertEquals('person', $ann1->metadata['category']);
    }

    public function test_sync_annotations_updates_existing_by_annotation_id(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
                'metadata' => ['title' => 'Original'],
            ],
        ]);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:50,60,200,100']],
                'metadata' => ['title' => 'Updated', 'category' => 'landmark'],
            ],
        ]);

        $model->refresh();

        $this->assertCount(1, $model->annotations);
        $annotation = $model->annotations->first();
        $this->assertEquals('ann-1', $annotation->annotation_id);
        $this->assertEquals('Updated', $annotation->metadata['title']);
        $this->assertEquals('landmark', $annotation->metadata['category']);
        $this->assertEquals(
            ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:50,60,200,100']],
            $annotation->geometry
        );
    }

    public function test_sync_annotations_deletes_annotations_not_in_provided_array(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
                'metadata' => ['title' => 'Keep'],
            ],
            [
                'annotation_id' => 'ann-2',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:200,100,300,200']],
                'metadata' => ['title' => 'Remove'],
            ],
        ]);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
                'metadata' => ['title' => 'Keep'],
            ],
        ]);

        $model->refresh();

        $this->assertCount(1, $model->annotations);
        $this->assertEquals('ann-1', $model->annotations->first()->annotation_id);
        $this->assertNull($model->annotations->firstWhere('annotation_id', 'ann-2'));
    }

    public function test_sync_annotations_with_empty_array_deletes_all(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
                'metadata' => ['title' => 'First'],
            ],
        ]);

        $this->assertCount(1, $model->annotations()->get());

        $model->syncAnnotations([]);

        $this->assertCount(0, $model->annotations()->get());
    }

    public function test_cascade_delete_removes_annotations_when_parent_is_deleted(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
                'metadata' => ['title' => 'Test'],
            ],
            [
                'annotation_id' => 'ann-2',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:200,100,300,200']],
                'metadata' => null,
            ],
        ]);

        $modelId = $model->id;
        $this->assertEquals(2, Annotation::where('annotatable_id', $modelId)->where('annotatable_type', TestAnnotatableModel::class)->count());

        $model->delete();

        $this->assertEquals(0, Annotation::where('annotatable_id', $modelId)->where('annotatable_type', TestAnnotatableModel::class)->count());
    }

    public function test_sync_annotations_with_null_metadata(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
            ],
        ]);

        $model->refresh();

        $this->assertCount(1, $model->annotations);
        $this->assertNull($model->annotations->first()->metadata);
    }

    public function test_sync_annotations_handles_geometry_as_json_string(): void
    {
        $model = TestAnnotatableModel::create(['name' => 'Test']);

        $geometryJson = json_encode(['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']]);

        $model->syncAnnotations([
            [
                'annotation_id' => 'ann-1',
                'geometry' => $geometryJson,
                'metadata' => ['title' => 'From JSON string'],
            ],
        ]);

        $model->refresh();

        $this->assertCount(1, $model->annotations);
        $this->assertEquals(
            ['selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=pixel:10,20,100,50']],
            $model->annotations->first()->geometry
        );
    }
}

/**
 * A simple test model that uses the HasAnnotations trait.
 */
class TestAnnotatableModel extends Model
{
    use HasAnnotations;

    protected $table = 'test_models';

    protected $guarded = [];
}
