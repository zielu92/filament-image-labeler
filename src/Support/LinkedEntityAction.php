<?php

namespace Zielu92\FilamentImageLabeler\Support;

use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The entity link action wrapper: the developer's template Action,
 * re-named to the internal registration key and mounted with the clicked
 * record injected (and re-validated) from the click's {type,id} args.
 * Also evaluates the per-record affordance (visible/url) the editor
 * payloads carry.
 */
final readonly class LinkedEntityAction
{
    public const NAME = 'openLinkedEntity';

    private Action $action;

    /**
     * @param  Closure(array<array-key, mixed>): Model  $recordResolver  validates the clicked {type,id} against the field's allow-list
     */
    public function __construct(Action $template, Closure $recordResolver)
    {
        $inner = $template->getMountUsing();

        $this->action = $template
            ->name(self::NAME)
            ->mountUsing(function (Action $action, array $arguments) use ($inner, $recordResolver): void {
                $action->record($recordResolver($arguments));

                $action->evaluate($inner, ['action' => $action, 'arguments' => $arguments]);
            });
    }

    /**
     * Stable registered instance - per-record closure evaluation shares
     * one visibility cache across a resolveEntities batch.
     */
    public function action(): Action
    {
        return $this->action;
    }

    /**
     * Evaluate the template's visibility and url for one record; no
     * affordance at all when the link is stale.
     *
     * @return array{0: bool, 1: string|null}
     */
    public function affordance(?Model $record): array
    {
        if (! $record instanceof Model) {
            return [false, null];
        }

        $this->action->record($record);

        try {
            return [$this->action->isVisible(), $this->action->getUrl()];
        } finally {
            $this->action->record(null);
        }
    }

    /**
     * Editor payload: registered action name plus the template label as
     * chip aria-label when string-resolvable, else the translation fallback.
     *
     * @return array{name: string, label: string}
     */
    public function payload(): array
    {
        $label = $this->action->getLabel();

        return [
            'name' => self::NAME,
            'label' => is_string($label) ? $label : __('filament-image-labeler::image-labeler.entity.view'),
        ];
    }

    /**
     * Trust boundary: the clicked {type,id} must be in the field's
     * linkableTo() allow-list and the record must exist.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public static function resolveRecord(LinkableTypes $types, array $arguments): Model
    {
        $type = $arguments['type'] ?? null;
        $id = $arguments['id'] ?? null;

        $linkable = is_string($type) ? $types->find($type) : null;

        if (! $linkable instanceof LinkableType || $id === null || $id === '') {
            throw new InvalidArgumentException('ImageLabel: entity link action called for a type outside linkableTo().');
        }

        /** @var class-string<Model> $model */
        $model = $linkable->model;

        $record = $model::query()->find(is_numeric($id) ? (int) $id : $id);

        if (! $record instanceof Model) {
            throw new InvalidArgumentException("ImageLabel: entity link action record [{$model}:#{$id}] does not exist.");
        }

        return $record;
    }
}
