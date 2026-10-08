<?php

namespace Zielu92\FilamentImageLabeler\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\Concerns\LinkableEntity;
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;
use Zielu92\FilamentImageLabeler\Support\EntityRef;
use Zielu92\FilamentImageLabeler\Support\LinkableType;

class EntityPerson extends Model
{
    use LinkableEntity;

    protected $table = 'entity_people';

    protected $guarded = [];
}

class EntityBuilding extends Model
{
    use LinkableEntity;

    protected $table = 'entity_buildings';

    protected $guarded = [];
}

class EntityTag extends Model
{
    protected $table = 'entity_tags';

    protected $guarded = [];
}

class EntityOwner extends Model
{
    use HasAnnotations;

    protected $table = 'entity_owners';

    protected $guarded = [];
}

class EntityLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->string('annotatable_type');
            $table->unsignedBigInteger('annotatable_id');
            $table->string('annotation_id');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('geometry');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['annotatable_type', 'annotatable_id']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('entity_people', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });

        Schema::create('entity_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('entity_tags', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('entity_owners', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['annotations', 'entity_people', 'entity_buildings', 'entity_tags', 'entity_owners'] as $table) {
            Schema::dropIfExists($table);
        }

        LinkableType::resetColumnCache();
        parent::tearDown();
    }

    // --- syncAnnotations contract (wf-006) ---

    public function test_sync_creates_annotation_with_entity_pair(): void
    {
        $owner = EntityOwner::create([]);
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [10, 10, 50, 50],
            'entity_type' => EntityPerson::class,
            'entity_id' => $person->id,
        ]]);

        $annotation = $owner->annotations()->first();
        $this->assertSame(EntityPerson::class, $annotation->entity_type);
        $this->assertSame($person->id, (int) $annotation->entity_id);
        $this->assertInstanceOf(EntityPerson::class, $annotation->entity);
    }

    public function test_sync_without_entity_keys_leaves_existing_link_untouched(): void
    {
        $owner = EntityOwner::create([]);
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [0, 0, 1, 1],
            'entity_type' => EntityPerson::class,
            'entity_id' => $person->id,
        ]]);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [5, 5, 5, 5],
        ]]);

        $annotation = $owner->annotations()->first();
        $this->assertSame(EntityPerson::class, $annotation->entity_type);
        $this->assertSame([5, 5, 5, 5], $annotation->geometry);
    }

    public function test_sync_with_explicit_null_clears_the_link(): void
    {
        $owner = EntityOwner::create([]);
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [0, 0, 1, 1],
            'entity_type' => EntityPerson::class,
            'entity_id' => $person->id,
        ]]);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [0, 0, 1, 1],
            'entity_type' => null,
            'entity_id' => null,
        ]]);

        $annotation = $owner->annotations()->first();
        $this->assertNull($annotation->entity_type);
        $this->assertNull($annotation->entity_id);
    }

    public function test_sync_half_pair_is_coerced_to_full_clear(): void
    {
        $owner = EntityOwner::create([]);
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [0, 0, 1, 1],
            'entity_type' => EntityPerson::class,
            'entity_id' => $person->id,
        ]]);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [0, 0, 1, 1],
            'entity_type' => EntityPerson::class,
            'entity_id' => null,
        ]]);

        $annotation = $owner->annotations()->first();
        $this->assertNull($annotation->entity_type);
        $this->assertNull($annotation->entity_id);
    }

    // --- LinkableEntity cleanup (wf-006) ---

    public function test_deleting_linked_entity_nulls_the_link_and_keeps_geometry(): void
    {
        $owner = EntityOwner::create([]);
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [1, 2, 3, 4],
            'entity_type' => EntityPerson::class,
            'entity_id' => $person->id,
        ]]);

        $person->delete();

        $annotation = $owner->annotations()->first();
        $this->assertNull($annotation->entity_type);
        $this->assertNull($annotation->entity_id);
        $this->assertSame([1, 2, 3, 4], $annotation->geometry);
    }

    public function test_linked_annotations_relation_finds_pointing_rows(): void
    {
        $owner = EntityOwner::create([]);
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $owner->syncAnnotations([[
            'annotation_id' => 'a1',
            'geometry' => [1, 2, 3, 4],
            'entity_type' => EntityPerson::class,
            'entity_id' => $person->id,
        ]]);

        $this->assertCount(1, $person->linkedAnnotations);
        $this->assertSame('a1', $person->linkedAnnotations->first()->annotation_id);
    }

    // --- LinkableType display + search (wf-003) ---

    public function test_display_convention_prefers_name_then_title_then_hash_id(): void
    {
        $building = EntityBuilding::create(['name' => 'Wroclaw Tower']);
        $tag = EntityTag::create(['code' => 'X1']);

        $type = LinkableType::make(EntityBuilding::class);
        $this->assertSame('Wroclaw Tower', $type->displayFor($building));
        $this->assertSame(['name'], $type->searchableColumns());

        $noColumns = LinkableType::make(EntityTag::class);
        $this->assertSame('#' . $tag->id, $noColumns->displayFor($tag));
        $this->assertSame([], $noColumns->searchableColumns());
    }

    public function test_custom_display_and_search_by_ands_words_across_columns(): void
    {
        EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);
        EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Nowak']);
        EntityPerson::create(['first_name' => 'Anna', 'last_name' => 'Kowalska']);

        $type = LinkableType::make(EntityPerson::class)
            ->display(fn (Model $m): string => $m->first_name . ' ' . $m->last_name)
            ->searchBy(['first_name', 'last_name']);

        $results = $type->search('Jan Kowalski');

        $this->assertCount(1, $results);
        $this->assertSame('Jan Kowalski', $results[0]['display']);
        $this->assertSame('Entity Person', $results[0]['label']);
        $this->assertSame(EntityPerson::class, $results[0]['type']);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        EntityBuilding::create(['name' => 'Tower 50%']);
        EntityBuilding::create(['name' => 'Tower 50m']);

        $results = LinkableType::make(EntityBuilding::class)->search('50%');

        $this->assertCount(1, $results);
        $this->assertSame('Tower 50%', $results[0]['display']);
    }

    // --- Field API (wf-003) ---

    public function test_linkable_to_rejects_custom_display_without_search_by(): void
    {
        $field = ImageLabel::make('shapes')
            ->linkableTo([LinkableType::make(EntityPerson::class)->display(fn (Model $m) => 'x')]);

        $this->expectException(\InvalidArgumentException::class);
        $field->getLinkableTypes();
    }

    public function test_linkable_to_rejects_convention_without_searchable_columns(): void
    {
        $field = ImageLabel::make('shapes')->linkableTo([LinkableType::make(EntityTag::class)]);

        $this->expectException(\InvalidArgumentException::class);
        $field->getLinkableTypes();
    }

    public function test_linkable_to_rejects_duplicates_and_non_types(): void
    {
        $field = ImageLabel::make('shapes')->linkableTo([
            LinkableType::make(EntityBuilding::class),
            LinkableType::make(EntityBuilding::class),
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $field->getLinkableTypes();
    }

    public function test_search_entities_fans_out_over_types(): void
    {
        EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);
        EntityBuilding::create(['name' => 'Jan Pawel Tower']);

        $field = ImageLabel::make('shapes')->linkableTo([
            LinkableType::make(EntityPerson::class)
                ->display(fn (Model $m): string => $m->first_name . ' ' . $m->last_name)
                ->searchBy(['first_name', 'last_name']),
            LinkableType::make(EntityBuilding::class),
        ]);

        $results = $field->searchEntities('Jan');

        $this->assertCount(2, $results);
        $types = array_column($results, 'type');
        $this->assertEqualsCanonicalizing([EntityPerson::class, EntityBuilding::class], $types);
    }

    public function test_resolve_entities_batches_per_type_and_falls_back(): void
    {
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $field = ImageLabel::make('shapes')->linkableTo([
            LinkableType::make(EntityBuilding::class),
            LinkableType::make(EntityPerson::class)
                ->display(fn (Model $m): string => $m->first_name . ' ' . $m->last_name)
                ->searchBy(['first_name', 'last_name']),
        ]);

        $resolved = $field->resolveEntities([
            ['type' => EntityPerson::class, 'id' => $person->id],
            ['type' => EntityPerson::class, 'id' => 9999],
            ['type' => EntityTag::class, 'id' => 1],
            ['type' => 'nonsense', 'id' => 'x'],
        ]);

        $this->assertSame('Jan Kowalski', $resolved[EntityPerson::class . ':' . $person->id]['display']);
        $this->assertSame('Entity Person #9999', $resolved[EntityPerson::class . ':9999']['display']);
        $this->assertSame('Entity Tag #1', $resolved[EntityTag::class . ':1']['display']);
        $this->assertArrayNotHasKey('nonsense:x', $resolved);
    }

    public function test_creatable_payload_and_action_registration(): void
    {
        $field = ImageLabel::make('shapes')->linkableTo([
            LinkableType::make(EntityPerson::class)
                ->display(fn (Model $m): string => $m->first_name)
                ->searchBy(['first_name']),
            LinkableType::make(EntityBuilding::class)
                ->creatable([]),
        ]);

        $payload = collect($field->getEntityTypesPayload())->keyBy('type');

        $this->assertFalse($payload[EntityPerson::class]['creatable']);
        $this->assertTrue($payload[EntityBuilding::class]['creatable']);
        $this->assertSame('createEntityEntityBuilding', $payload[EntityBuilding::class]['action']);

        $this->assertNotNull($field->getAction('createEntityEntityBuilding'));
        $this->assertNull($field->getAction('createEntityEntityPerson'));
    }

    public function test_allow_entity_creation_restricts_by_list_false_and_allows(): void
    {
        $build = fn (array | bool $policy): ImageLabel => ImageLabel::make('shapes')
            ->linkableTo([
                LinkableType::make(EntityBuilding::class)->creatable([]),
                LinkableType::make(EntityTag::class)
                    ->display(fn (Model $m): string => (string) $m->code)
                    ->searchBy(['code'])
                    ->creatable([]),
            ])
            ->allowEntityCreation($policy);

        $flags = fn (ImageLabel $f): array => collect($f->getEntityTypesPayload())->pluck('creatable', 'type')->all();

        $all = $build(true);
        $this->assertTrue($flags($all)[EntityBuilding::class]);
        $this->assertTrue($flags($all)[EntityTag::class]);
        $this->assertNotNull($all->getAction('createEntityEntityTag'));

        $none = $build(false);
        $this->assertSame([false, false], array_values($flags($none)));
        $this->assertNull($none->getAction('createEntityEntityBuilding'));

        $selected = $build([EntityTag::class]);
        $this->assertSame([false, true], array_values($flags($selected)));
        $this->assertNull($selected->getAction('createEntityEntityBuilding'));
        $this->assertNotNull($selected->getAction('createEntityEntityTag'));
    }

    public function test_allow_entity_creation_rejects_unknown_or_non_creatable_type(): void
    {
        $field = ImageLabel::make('shapes')
            ->linkableTo([
                LinkableType::make(EntityBuilding::class),
                LinkableType::make(EntityTag::class)
                    ->display(fn (Model $m): string => (string) $m->code)
                    ->searchBy(['code']),
            ])
            ->allowEntityCreation([EntityPerson::class]);

        $this->expectException(\InvalidArgumentException::class);
        $field->getEntityTypesPayload();
    }

    // --- Suggestions payload (wf-007) ---

    public function test_suggestion_accepts_entity_as_ref_or_array_and_emits_pending(): void
    {
        $person = EntityPerson::create(['first_name' => 'Jan', 'last_name' => 'Kowalski']);

        $withRef = AnnotationSuggestion::box('USB', 0.1, 0.2, 0.3, 0.4, EntityRef::fromModel($person));
        $withArray = AnnotationSuggestion::box('LAN', 0, 0, 1, 1, ['type' => EntityPerson::class, 'id' => $person->id]);
        $garbage = AnnotationSuggestion::box('COM', 0, 0, 1, 1, ['type' => '', 'id' => null]);

        $pending = AnnotationSuggestion::toPending([$withRef, $withArray, $garbage]);

        $this->assertSame(['type' => EntityPerson::class, 'id' => $person->id], $pending[0]['entity']);
        $this->assertSame(['type' => EntityPerson::class, 'id' => $person->id], $pending[1]['entity']);
        $this->assertNull($pending[2]['entity']);
    }

    public function test_array_suggestions_carry_entity_key(): void
    {
        $suggestion = AnnotationSuggestion::fromArray([
            'label' => 'x',
            'box' => [0, 0, 1, 1],
            'entity' => ['type' => EntityPerson::class, 'id' => 7],
        ]);

        $this->assertInstanceOf(EntityRef::class, $suggestion->entity);
        $this->assertSame(7, $suggestion->entity->id);
    }

    // --- Migration smoke ---

    public function test_package_migrations_add_entity_columns(): void
    {
        Schema::drop('annotations');
        LinkableType::resetColumnCache();

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('annotations', 'entity_type'));
        $this->assertTrue(Schema::hasColumn('annotations', 'entity_id'));
    }
}
