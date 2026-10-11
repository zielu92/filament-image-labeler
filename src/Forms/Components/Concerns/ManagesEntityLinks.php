<?php

namespace Zielu92\FilamentImageLabeler\Forms\Components\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Livewire\Attributes\Renderless;
use Zielu92\FilamentImageLabeler\Support\LinkableType;
use Zielu92\FilamentImageLabeler\Support\LinkableTypes;
use Zielu92\FilamentImageLabeler\Support\LinkedEntityAction;

/**
 * Entity links on an ImageLabel field: the fluent configuration surface
 * (linkableTo, allowEntityCreation, entityAction) and the Livewire-facing
 * endpoints (searchEntities, resolveEntities). The logic lives in
 * Support\LinkableTypes and Support\LinkedEntityAction; this trait is the
 * thin field-side binding.
 *
 * Requires ImageLabel (Filament Field): evaluate(), getName().
 */
trait ManagesEntityLinks
{
    /** @var array<array-key, mixed>|Closure|null */
    protected array | Closure | null $linkableTypes = null;

    protected ?LinkableTypes $typeRegistry = null;

    /** @var array<array-key, class-string>|bool|Closure */
    protected array | bool | Closure $entityCreation = true;

    protected Action | Closure | null $entityAction = null;

    protected ?LinkedEntityAction $linkedEntityAction = null;

    /** Upper bound on refs one resolveEntities RPC may resolve, mirroring the searchEntities cap. */
    private const MAX_ENTITY_REFS = 50;

    /**
     * Enable entity links: shapes on this field may be linked to records of
     * the given types. Each entry is a configured LinkableType.
     *
     * @param  array<array-key, LinkableType>|Closure  $types
     */
    public function linkableTo(array | Closure $types): static
    {
        $this->linkableTypes = $types;
        $this->typeRegistry = null;

        return $this;
    }

    public function hasLinkableTypes(): bool
    {
        return $this->linkableTypes !== null;
    }

    /**
     * @return list<LinkableType>
     */
    public function getLinkableTypes(): array
    {
        return $this->typeRegistry()->types;
    }

    protected function typeRegistry(): LinkableTypes
    {
        return $this->typeRegistry ??= new LinkableTypes($this->evaluate($this->linkableTypes) ?? []);
    }

    /**
     * Entity picker search: fan the term out over every linkable type,
     * matches carrying the entity link action affordance when one is set.
     *
     * @return list<array{type: string, id: int|string, display: string, label: string, visible?: bool, url?: string|null}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function searchEntities(string $search): array
    {
        if (blank($search) || ! $this->hasLinkableTypes()) {
            return [];
        }

        $wrapper = $this->linkedEntityAction();

        return $this->typeRegistry()->search(
            trim($search),
            $wrapper instanceof LinkedEntityAction ? $wrapper->affordance(...) : null,
        );
    }

    /**
     * Resolve display strings (plus per-ref affordance when an entityAction
     * is configured) for the refs the current shapes point at. Oversized
     * batches (a persisted-state flood) are refused: returns [] over the cap.
     *
     * @param  list<array<array-key, mixed>>  $refs
     * @return array<string, array{display: string, label: string, visible?: bool, url?: string|null}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function resolveEntities(array $refs): array
    {
        if (! $this->hasLinkableTypes() || count($refs) > self::MAX_ENTITY_REFS) {
            return [];
        }

        $wrapper = $this->linkedEntityAction();

        return $this->typeRegistry()->resolveRefs(
            $refs,
            $wrapper instanceof LinkedEntityAction ? $wrapper->affordance(...) : null,
        );
    }

    /**
     * The entity link action: one template Action whose record-aware
     * closures run per linked entity. Mounted with the clicked record
     * injected; a non-null url() navigates same tab, modal config opens
     * in the editor, visible() false hides the affordance for a record.
     */
    public function entityAction(Action | Closure | null $action): static
    {
        $this->entityAction = $action;
        $this->linkedEntityAction = null;

        return $this;
    }

    public function linkedEntityAction(): ?LinkedEntityAction
    {
        if ($this->linkedEntityAction instanceof LinkedEntityAction) {
            return $this->linkedEntityAction;
        }

        if ($this->entityAction === null) {
            return null;
        }

        $template = $this->evaluate($this->entityAction);

        if (! $template instanceof Action) {
            throw new InvalidArgumentException('ImageLabel: entityAction() accepts a Filament Action.');
        }

        return $this->linkedEntityAction = new LinkedEntityAction(
            $template,
            fn (array $arguments): Model => LinkedEntityAction::resolveRecord($this->typeRegistry(), $arguments),
        );
    }

    /**
     * Entity link action config for the editor.
     *
     * @return array{name: string, label: string}|null
     */
    public function getEntityActionPayload(): ?array
    {
        return $this->linkedEntityAction()?->payload();
    }

    /**
     * Field-level control over in-editor record creation:
     *  - false: never offer creation,
     *  - true (default): offer it for every linkable type that defines a
     *    creatable() schema,
     *  - array of model classes: exactly those (each must define a
     *    creatable() schema, or this throws at dev time).
     *
     * @param  array<array-key, class-string>|bool|Closure  $types
     */
    public function allowEntityCreation(array | bool | Closure $types = true): static
    {
        $this->entityCreation = $types;

        return $this;
    }

    /**
     * @return list<LinkableType>
     */
    protected function creatableTypes(): array
    {
        if (! $this->hasLinkableTypes()) {
            return [];
        }

        return $this->typeRegistry()->creatable($this->evaluate($this->entityCreation));
    }

    /**
     * Picker payload for the editor: which linkable types exist, how they
     * are named and which can be created from the editor (and under which
     * registered action name).
     *
     * @return list<array{type: string, label: string, creatable: bool, action: string}>
     */
    public function getEntityTypesPayload(): array
    {
        if (! $this->hasLinkableTypes()) {
            return [];
        }

        return $this->typeRegistry()->typesPayload($this->creatableTypes());
    }

    public function creationActionName(string $model): string
    {
        return LinkableTypes::creationActionName($model);
    }

    /**
     * One CreateAction per type enabled by allowEntityCreation(); the action
     * returns the created ref so the editor can bind it to the active shape.
     *
     * @return list<Action>
     */
    protected function creatableActions(): array
    {
        $actions = [];

        foreach ($this->creatableTypes() as $type) {
            $actions[] = CreateAction::make($this->creationActionName($type->model))
                ->label(__('filament-image-labeler::image-labeler.entity.create') . ' ' . $type->typeName())
                ->model($type->model)
                ->schema($type->getCreationSchema())
                ->action(function (CreateAction $action, array $data): array {
                    /** @var class-string<Model> $model */
                    $model = $action->getModel();

                    $record = $model::create($data);

                    $action->success();

                    return [
                        'type' => $model,
                        'id' => $record->getKey(),
                    ];
                });
        }

        return $actions;
    }
}
