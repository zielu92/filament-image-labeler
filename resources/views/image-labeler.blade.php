<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $palette = $field->getColorPalette();
    @endphp
    <div
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            anno: null,
            imageUrl: '',
            isMultiple: {{ $field->isMultiple() ? 'true' : 'false' }},
            palette: @js($palette),
            noneColor: @js(\Zielu92\FilamentImageLabeler\Support\AnnotationColor::NONE_COLOR),
            unlabeledText: @js(__('filament-image-labeler::image-labeler.labels.unlabeled')),

            activeTool: 'select',
            selIds: [],
            canUndo: false,
            canRedo: false,
            editingLabel: null,
            suppressSel: false,
            detailsName: '',
            detailsColor: '#6b7280',
            scale: 1,
            readOnly: {{ $field->isReadOnly() ? 'true' : 'false' }},
            tooltip: { visible: false, text: '', color: '#6b7280', x: 0, y: 0 },

            hashColor(value) {
                let hash = 0;
                for (let i = 0; i < value.length; i++) {
                    hash = ((hash << 5) - hash) + value.charCodeAt(i);
                    hash |= 0;
                }
                return this.palette[Math.abs(hash) % this.palette.length];
            },

            defaultColorFor(label) {
                return label ? this.hashColor(label) : this.noneColor;
            },

            metaFor(id) {
                const s = (this.state || []).find(x => x.id === id);
                return { label: s?.label ?? '', color: s?.color || this.noneColor };
            },

            styleExpr() {
                return (annotation) => {
                    const m = this.metaFor(annotation.id);
                    const selected = this.selIds.includes(annotation.id);
                    return {
                        fill: m.color + (selected ? '4d' : '26'),
                        stroke: m.color,
                        strokeWidth: selected ? 3 : 2,
                    };
                };
            },

            restyle() {
                try { this.anno?.setStyle(this.styleExpr()); } catch (e) {}
            },

            refreshHistory() {
                try {
                    this.canUndo = !!this.anno?.canUndo();
                    this.canRedo = !!this.anno?.canRedo();
                } catch (e) {
                    this.canUndo = false;
                    this.canRedo = false;
                }
            },

            initAnnotorious() {
                if (this.anno) {
                    this.anno.destroy();
                    this.anno = null;
                }

                this.anno = window.Annotorious.init({
                    image: this.$refs.imageToLabel,
                    style: this.styleExpr(),
                    ...(this.readOnly ? { userSelectAction: 'NONE' } : {}),
                });

                (this.state || []).forEach(ann => this.anno.addAnnotation(ann));

                if (this.readOnly) {
                    this.anno.setDrawingEnabled(false);

                    this.anno.on('mouseEnterAnnotation', (annotation) => {
                        const m = this.metaFor(annotation.id);
                        if (!m.label) return;
                        this.tooltip.text = m.label;
                        this.tooltip.color = m.color;
                        this.tooltip.visible = true;
                    });

                    this.anno.on('mouseLeaveAnnotation', () => {
                        this.tooltip.visible = false;
                    });

                    return;
                }

                this.setTool(this.activeTool);

                this.anno.on('createAnnotation', (annotation) => {
                    if (!this.isMultiple) {
                        this.anno.getAnnotations().forEach(ann => {
                            if (ann.id !== annotation.id) this.anno.removeAnnotation(ann.id);
                        });
                    }
                    if (!(this.state || []).find(s => s.id === annotation.id)) {
                        this.state = [...(this.state || []), {
                            id: annotation.id,
                            target: annotation.target,
                            label: '',
                            color: this.noneColor,
                        }];
                    }
                    this.refreshHistory();
                });

                this.anno.on('updateAnnotation', (annotation) => {
                    this.state = (this.state || []).map(s =>
                        s.id === annotation.id ? { ...s, target: annotation.target } : s
                    );
                    this.refreshHistory();
                });

                this.anno.on('deleteAnnotation', (annotation) => {
                    this.state = (this.state || []).filter(s => s.id !== annotation.id);
                    this.refreshHistory();
                });

                this.anno.on('selectionChanged', (selection) => {
                    this.selIds = (selection || []).map(a => a.id);
                    if (this.suppressSel) {
                        this.suppressSel = false;
                    } else {
                        this.editingLabel = null;
                    }
                    this.restyle();
                });
            },

            init() {
                setTimeout(() => {
                    if (!this.imageUrl) {
                        this.imageUrl = this.$refs.urlFlag?.dataset.url || '';
                    }

                    this.initAnnotorious();
                    this.updateScale();

                    // External (server-side) state changes: sync geometry into the canvas.
                    this.$watch('state', (newState) => {
                        if (!this.anno) return;
                        const current = this.anno.getAnnotations().map(a => a.id);
                        const next = (newState || []).map(a => a.id);
                        current.forEach(id => {
                            if (!next.includes(id)) this.anno.removeAnnotation(id);
                        });
                        (newState || []).forEach(ann => {
                            if (!current.includes(ann.id)) this.anno.addAnnotation(ann);
                        });
                        this.restyle();
                    });

                    // Details fields follow the active label.
                    this.$watch('activeLabel', (label) => {
                        if (label === null) return;
                        this.detailsName = label;
                        const g = this.labelGroups.find(x => x.label === label);
                        this.detailsColor = g ? g.color : this.noneColor;
                    });
                }, 100);

                this.$nextTick(() => {
                    if (!this.$refs.urlFlag) return;

                    new MutationObserver(() => this.setImage(this.$refs.urlFlag.dataset.url || ''))
                        .observe(this.$refs.urlFlag, { attributes: true, attributeFilter: ['data-url'] });
                });

                Livewire.on('image-labeler-update-url', (data) => {
                    const [url, path] = Array.isArray(data) ? data : [data];
                    if (path && path !== '{{ $getStatePath() }}') return;
                    if (url) this.setImage(url);
                });

                window.addEventListener('resize', () => this.updateScale());
            },

            onKeydown(e) {
                if (this.readOnly || e.defaultPrevented || !this.anno) return;
                const el = document.activeElement;
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(el?.tagName) || el?.isContentEditable) return;

                if (e.key === 'Delete' || e.key === 'Backspace') {
                    if (this.selIds.length) { e.preventDefault(); this.deleteSelection(); }
                } else if (e.key === 'Escape') {
                    this.anno.cancelDrawing?.();
                    if (this.selIds.length) this.anno.cancelSelected?.();
                }
            },

            setImage(url) {
                const initial = !this.imageUrl;
                if (!url || url === this.imageUrl) return;
                this.imageUrl = url;

                const img = this.$refs.imageToLabel;
                img.onload = () => {
                    if (!initial) {
                        this.state = [];
                        this.selIds = [];
                        this.editingLabel = null;
                    }
                    this.updateScale();
                    this.initAnnotorious();
                };
                img.src = url;
            },

            get shapes() {
                return this.state || [];
            },

            get labelGroups() {
                const groups = [];
                const index = {};
                this.shapes.forEach(s => {
                    const label = s.label ?? '';
                    if (!(label in index)) {
                        index[label] = groups.length;
                        groups.push({
                            label,
                            color: s.color || this.defaultColorFor(label),
                            ids: [s.id],
                        });
                    } else {
                        groups[index[label]].ids.push(s.id);
                    }
                });
                return groups;
            },

            get activeLabel() {
                if (this.editingLabel !== null) return this.editingLabel;
                const selected = this.shapes.find(s => this.selIds.includes(s.id));
                return selected ? (selected.label ?? '') : null;
            },

            get detailsVisible() {
                return this.activeLabel !== null;
            },

            setTool(tool) {
                this.activeTool = tool;
                if (!this.anno) return;
                if (tool === 'select') {
                    this.anno.setDrawingEnabled(false);
                } else {
                    this.anno.setDrawingEnabled(true);
                    this.anno.setDrawingTool(tool);
                }
            },

            labelTool() {
                this.setTool('select');
                this.$nextTick(() => this.$refs.nameInput?.focus());
            },

            pickGroup(label) {
                const g = this.labelGroups.find(x => x.label === label);
                if (!g) return;
                this.editingLabel = label;
                if (g.ids[0] && this.anno) {
                    this.suppressSel = true;
                    this.anno.setSelected([g.ids[0]], false);
                }
            },

            editGroup(label) {
                this.pickGroup(label);
                this.$nextTick(() => this.$refs.nameInput?.focus());
            },

            commitName() {
                const old = this.activeLabel;
                if (old === null) return;
                const next = this.detailsName.trim();
                if (next === old) return;

                const existing = this.labelGroups.find(g => g.label === next);
                const color = existing ? existing.color : this.defaultColorFor(next);

                this.state = this.shapes.map(s =>
                    ((s.label ?? '') === old) ? { ...s, label: next, color } : s
                );
                this.editingLabel = next;
                this.detailsColor = color;
                this.restyle();
            },

            commitColor(color) {
                const label = this.activeLabel;
                if (label === null) return;
                this.detailsColor = color;
                this.state = this.shapes.map(s =>
                    ((s.label ?? '') === label) ? { ...s, color } : s
                );
                this.restyle();
            },

            deleteGroup(label) {
                this.shapes.filter(s => (s.label ?? '') === label)
                    .forEach(s => this.anno?.removeAnnotation(s.id));
                if (this.editingLabel === label) this.editingLabel = null;
            },

            deleteSelection() {
                if (this.selIds.length) {
                    [...this.selIds].forEach(id => this.anno?.removeAnnotation(id));
                } else {
                    this.anno?.clearAnnotations();
                }
            },

            undo() { this.anno?.undo(); this.syncGeometry(); },
            redo() { this.anno?.redo(); this.syncGeometry(); },

            syncGeometry() {
                const anns = this.anno?.getAnnotations() || [];
                const byId = {};
                this.shapes.forEach(s => byId[s.id] = s);
                this.state = anns.map(a => ({
                    id: a.id,
                    target: a.target,
                    label: byId[a.id]?.label ?? '',
                    color: byId[a.id]?.color ?? this.noneColor,
                }));
                this.restyle();
            },

            updateScale() {
                const img = this.$refs.imageToLabel;
                this.scale = (img && img.naturalWidth) ? img.clientWidth / img.naturalWidth : 1;
            },

            boundsFor(target) {
                const sel = target?.selector;
                if (!sel) return null;

                if (sel.geometry) {
                    const b = sel.geometry.bounds;
                    if (b) return { x: b.minX, y: b.minY, w: b.maxX - b.minX, h: b.maxY - b.minY };
                    if (sel.geometry.points?.length) {
                        const xs = sel.geometry.points.map(p => p[0]);
                        const ys = sel.geometry.points.map(p => p[1]);
                        return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) };
                    }
                    return null;
                }

                if (sel.type === 'FragmentSelector') {
                    const m = /xywh=pixel:([-\d.]+),([-\d.]+),([-\d.]+),([-\d.]+)/.exec(sel.value || '');
                    if (m) return { x: +m[1], y: +m[2], w: +m[3], h: +m[4] };
                    return null;
                }

                if (sel.type === 'SvgSelector') {
                    const d = /d=\x22([^\x22]+)\x22/.exec(sel.value || '');
                    if (!d) return null;
                    const nums = (d[1].match(/-?[\d.]+/g) || []).map(Number);
                    const xs = [], ys = [];
                    for (let i = 0; i + 1 < nums.length; i += 2) { xs.push(nums[i]); ys.push(nums[i + 1]); }
                    if (!xs.length) return null;
                    return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) };
                }

                return null;
            },

            pillStyle(s) {
                const b = this.boundsFor(s.target);
                if (!b) return 'display:none';
                return `left:${b.x * this.scale}px; top:${b.y * this.scale - 6}px; background-color:${s.color || this.noneColor};`;
            },

            boxStyle(s) {
                const b = this.boundsFor(s.target);
                if (!b) return 'display:none';
                return `left:${b.x * this.scale - 2}px; top:${(b.y * this.scale) - 2}px; width:${b.w * this.scale + 4}px; height:${b.h * this.scale + 4}px; border-color:${s.color || this.noneColor};`;
            },
        }"
        class="filament-il-root"
    >
        <span x-ref="urlFlag" hidden data-url="{{ $field->getImageUrl() }}"></span>

        <!-- IMAGE -->
        <div wire:ignore class="filament-il-image" x-on:mousemove="if (tooltip.visible) { tooltip.x = $event.offsetX; tooltip.y = $event.offsetY; }" x-on:keydown.window="onKeydown($event)">
                <img
                    x-ref="imageToLabel"
                    :src="imageUrl"
                    x-on:load="updateScale()"
                    class="filament-il-img"
                    alt="{{ __('filament-image-labeler::image-labeler.image_alt') }}"
                />

                <!-- selection emphasis -->
                <template x-for="s in shapes.filter(x => selIds.includes(x.id))" :key="'box-' + s.id">
                    <div class="filament-il-box" :style="boxStyle(s)"></div>
                </template>

                <!-- label pills -->
                <template x-for="s in shapes.filter(x => x.label && ! readOnly)" :key="'pill-' + s.id">
                    <div
                        class="filament-il-pill"
                        :style="pillStyle(s)"
                        x-text="s.label"
                        x-on:click="anno && anno.setSelected([s.id], false)"
                    ></div>
                </template>

                <!-- hover tooltip (read-only) -->
                <div
                    x-show="tooltip.visible"
                    x-cloak
                    class="filament-il-tooltip"
                    :style="`left: ${tooltip.x + 12}px; top: ${tooltip.y - 8}px; border-left-color:${tooltip.color};`"
                    x-text="tooltip.text"
                ></div>
            </div>

        @if(! $field->isReadOnly())
        <!-- TOOLBAR (below image) -->
        <div wire:ignore class="filament-il-toolbar">
                <div class="filament-il-toolbar-group">
                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-m-cursor-arrow-rays"
                        x-on:click="setTool('select')"
                        x-bind:class="activeTool === 'select' ? 'filament-il-tool-active' : ''"
                    >
                        {{ __('filament-image-labeler::image-labeler.tools.select') }}
                    </x-filament::button>

                    @if($field->isSquareEnabled())
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-m-square-3-stack-3d"
                            x-on:click="setTool('rectangle')"
                            x-bind:class="activeTool === 'rectangle' ? 'filament-il-tool-active' : ''"
                        >
                            {{ __('filament-image-labeler::image-labeler.tools.rectangle') }}
                        </x-filament::button>
                    @endif

                    @if($field->isPolygonEnabled())
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-m-paint-brush"
                            x-on:click="setTool('polygon')"
                            x-bind:class="activeTool === 'polygon' ? 'filament-il-tool-active' : ''"
                        >
                            {{ __('filament-image-labeler::image-labeler.tools.polygon') }}
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-m-tag"
                        x-on:click="labelTool()"
                        x-bind:class="activeTool === 'select' && detailsVisible ? 'filament-il-tool-active' : ''"
                    >
                        {{ __('filament-image-labeler::image-labeler.tools.label') }}
                    </x-filament::button>
                </div>

                <div class="filament-il-toolbar-group">
                    <x-filament::icon-button
                        icon="heroicon-m-arrow-uturn-left"
                        color="gray"
                        size="sm"
                        x-on:click="undo()"
                        x-bind:disabled="!canUndo"
                        title="{{ __('filament-image-labeler::image-labeler.tools.undo') }}"
                        aria-label="{{ __('filament-image-labeler::image-labeler.tools.undo') }}"
                    />
                    <x-filament::icon-button
                        icon="heroicon-m-arrow-uturn-right"
                        color="gray"
                        size="sm"
                        x-on:click="redo()"
                        x-bind:disabled="!canRedo"
                        title="{{ __('filament-image-labeler::image-labeler.tools.redo') }}"
                        aria-label="{{ __('filament-image-labeler::image-labeler.tools.redo') }}"
                    />
                    @if($field->isClearEnabled())
                        <x-filament::icon-button
                            icon="heroicon-m-trash"
                            color="danger"
                            size="sm"
                            x-on:click="deleteSelection()"
                            title="{{ __('filament-image-labeler::image-labeler.tools.delete') }}"
                            aria-label="{{ __('filament-image-labeler::image-labeler.tools.delete') }}"
                        />
                    @endif
                </div>
            </div>

        <!-- LABELS + DETAILS -->
        <div wire:ignore class="filament-il-panels">
                <div class="filament-il-panel">
                    <h4 class="filament-il-panel-title">
                        {{ __('filament-image-labeler::image-labeler.labels.title') }}
                    </h4>

                    <div class="filament-il-rows">
                        <template x-for="g in labelGroups" :key="g.label">
                            <div
                                class="filament-il-row"
                                x-on:click="pickGroup(g.label)"
                                :class="activeLabel === g.label ? 'filament-il-row-active' : ''"
                            >
                                <span class="filament-il-dot" :style="`background-color:${g.color}`"></span>
                                <span class="filament-il-row-label" x-text="g.label || unlabeledText"></span>
                                <x-filament::icon-button
                                    icon="heroicon-m-pencil-square"
                                    color="gray"
                                    size="xs"
                                    x-on:click.stop="editGroup(g.label)"
                                    title="{{ __('filament-image-labeler::image-labeler.labels.edit') }}"
                                    aria-label="{{ __('filament-image-labeler::image-labeler.labels.edit') }}"
                                />
                                <x-filament::icon-button
                                    icon="heroicon-m-trash"
                                    color="danger"
                                    size="xs"
                                    x-on:click.stop="deleteGroup(g.label)"
                                    title="{{ __('filament-image-labeler::image-labeler.labels.delete') }}"
                                    aria-label="{{ __('filament-image-labeler::image-labeler.labels.delete') }}"
                                />
                            </div>
                        </template>

                        <p x-show="labelGroups.length === 0" class="filament-il-empty">
                            {{ __('filament-image-labeler::image-labeler.labels.empty') }}
                        </p>
                    </div>
                </div>

                <div class="filament-il-panel">
                    <h4 class="filament-il-panel-title">
                        {{ __('filament-image-labeler::image-labeler.details.title') }}
                    </h4>

                    <div x-show="detailsVisible" class="filament-il-details">
                        <div>
                            <label class="filament-il-field-label">
                                {{ __('filament-image-labeler::image-labeler.details.label') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    x-ref="nameInput"
                                    x-model="detailsName"
                                    x-on:change="commitName()"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <div>
                            <label class="filament-il-field-label">
                                {{ __('filament-image-labeler::image-labeler.details.color') }}
                            </label>
                            <div class="filament-il-color-row">
                                <input
                                    type="color"
                                    class="filament-il-color-input"
                                    x-model="detailsColor"
                                    x-on:input="commitColor($event.target.value)"
                                />
                                <x-filament::input.wrapper class="filament-il-hex">
                                    <x-filament::input
                                        type="text"
                                        x-model="detailsColor"
                                        x-on:change="commitColor(detailsColor)"
                                    />
                                </x-filament::input.wrapper>
                            </div>
                        </div>
                    </div>

                    <p x-show="!detailsVisible" class="filament-il-empty">
                        {{ __('filament-image-labeler::image-labeler.details.hint') }}
                    </p>
                </div>
        </div>
        @endif
    </div>
</x-dynamic-component>
