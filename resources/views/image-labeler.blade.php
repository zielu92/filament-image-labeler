<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            anno: null,
            imageUrl: '{{ $field->getImageUrl() }}',
            activeTool: '{{ $field->isSquareEnabled() ? 'rectangle' : ($field->isPolygonEnabled() ? 'polygon' : '') }}',
            isMultiple: {{ $field->isMultiple() ? 'true' : 'false' }},
            tooltip: { visible: false, text: '', x: 0, y: 0 },
            hasColors: {{ $field->hasColoredAnnotations() ? 'true' : 'false' }},

            colorPalette: {{ json_encode($field->getColorPalette() ?? []) }},

            hashColor(id) {
                if (!this.hasColors || this.colorPalette.length === 0) return null;
                let hash = 0;
                for (let i = 0; i < id.length; i++) {
                    hash = ((hash << 5) - hash) + id.charCodeAt(i);
                    hash |= 0;
                }
                return this.colorPalette[Math.abs(hash) % this.colorPalette.length];
            },

            getAnnotationLabel(annotationId) {
                // Read repeater state from Livewire to get metadata
                const repeater = $wire.get('data.annotation_repeater') || {};
                const items = Object.values(repeater);
                const item = items.find(i => i && i.annotation_id === annotationId);
                if (!item) return null;

                const parts = [];
                if (item.title) parts.push(item.title);
                if (item.category) parts.push('[' + item.category + ']');
                return parts.length > 0 ? parts.join(' ') : null;
            },

            initAnnotorious() {
                if (this.anno) {
                    this.anno.destroy();
                    this.anno = null;
                }

                this.anno = window.Annotorious.init({
                    image: this.$refs.imageToLabel,
                    style: (annotation) => {
                        const color = this.hashColor(annotation.id);
                        if (!color) return {};
                        return {
                            fill: color + '33',
                            stroke: color,
                            strokeWidth: 2
                        };
                    }
                });

                if (this.state && this.state.length > 0) {
                    this.anno.setAnnotations(this.state);
                }

                if (this.activeTool) {
                    this.anno.setDrawingTool(this.activeTool);
                }

                this.anno.on('createAnnotation', (annotation) => {
                    this.enforceMultipleLogic(annotation);
                    this.syncToLivewire();
                });

                this.anno.on('updateAnnotation', () => this.syncToLivewire());
                this.anno.on('deleteAnnotation', () => this.syncToLivewire());

                // Tooltip on hover
                this.anno.on('mouseEnterAnnotation', (annotation) => {
                    const label = this.getAnnotationLabel(annotation.id);
                    if (label) {
                        this.tooltip.text = label;
                        this.tooltip.visible = true;
                    }
                });

                this.anno.on('mouseLeaveAnnotation', () => {
                    this.tooltip.visible = false;
                });
            },

            init() {
                setTimeout(() => {
                    this.initAnnotorious();

                    // Watch for external state changes (e.g. repeater deletions)
                    this.$watch('state', (newState) => {
                        if (!this.anno) return;
                        const currentIds = this.anno.getAnnotations().map(a => a.id);
                        const newIds = (newState || []).map(a => a.id);

                        currentIds.forEach(id => {
                            if (!newIds.includes(id)) {
                                this.anno.removeAnnotation(id);
                            }
                        });

                        (newState || []).forEach(ann => {
                            if (!currentIds.includes(ann.id)) {
                                this.anno.addAnnotation(ann);
                            }
                        });
                    });
                }, 100);

                // Listen for image URL updates from Livewire
                Livewire.on('image-labeler-update-url', (data) => {
                    const url = Array.isArray(data) ? data[0] : data;
                    if (url && url !== this.imageUrl) {
                        this.imageUrl = url;
                        this.$refs.imageToLabel.src = url;
                        this.$refs.imageToLabel.onload = () => {
                            this.state = [];
                            this.initAnnotorious();
                        };
                    }
                });
            },

            setTool(tool) {
                this.activeTool = tool;
                this.anno.setDrawingTool(tool);
            },

            enforceMultipleLogic(newAnnotation) {
                if (!this.isMultiple) {
                    const all = this.anno.getAnnotations();
                    all.forEach(ann => {
                        if (ann.id !== newAnnotation.id) {
                            this.anno.removeAnnotation(ann.id);
                        }
                    });
                }
            },

            syncToLivewire() {
                const currentAnnotations = this.anno.getAnnotations();
                this.state = currentAnnotations.map(ann => ({
                    id: ann.id,
                    target: ann.target
                }));
            }
        }"
        class="flex flex-col gap-4"
    >
        <!-- TOOLBAR -->
        <div>
            @if($field->isSquareEnabled())
                <x-filament::button
                    x-on:click="setTool('rectangle')"
                    x-bind:color="activeTool === 'rectangle' ? 'primary' : 'gray'"
                    size="sm"
                    icon="heroicon-m-square-3-stack-3d"
                >
                    {{ __('filament-image-labeler::image-labeler.tools.rectangle') }}
                </x-filament::button>
            @endif

            @if($field->isPolygonEnabled())
                <x-filament::button
                    x-on:click="setTool('polygon')"
                    x-bind:color="activeTool === 'polygon' ? 'primary' : 'gray'"
                    size="sm"
                    icon="heroicon-m-variable"
                >
                    {{ __('filament-image-labeler::image-labeler.tools.polygon') }}
                </x-filament::button>
            @endif

            @if($field->isClearEnabled())
                <x-filament::button
                    x-on:click="anno.clearAnnotations(); syncToLivewire();"
                    color="danger"
                    size="sm"
                    icon="heroicon-m-trash"
                >
                    {{ __('filament-image-labeler::image-labeler.tools.clear_all') }}
                </x-filament::button>
            @endif
        </div>

        <!-- IMAGE WRAPPER -->
        <div
            class="relative border border-gray-300 rounded-lg overflow-hidden bg-gray-50 shadow-sm dark:border-gray-700 dark:bg-gray-900"
            x-on:mousemove="if (tooltip.visible) { tooltip.x = $event.offsetX; tooltip.y = $event.offsetY; }"
        >
            <img x-ref="imageToLabel" :src="imageUrl" class="block w-full max-w-full" alt="{{ __('filament-image-labeler::image-labeler.image_alt') }}" />

            <!-- Tooltip -->
            <div
                x-show="tooltip.visible"
                x-cloak
                class="absolute pointer-events-none z-50 px-2 py-1 text-xs font-medium text-white bg-gray-900 rounded shadow-lg dark:bg-gray-700 whitespace-nowrap"
                :style="`left: ${tooltip.x + 12}px; top: ${tooltip.y - 8}px;`"
                x-text="tooltip.text"
            ></div>
        </div>
    </div>
</x-dynamic-component>
