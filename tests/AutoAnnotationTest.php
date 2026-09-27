<?php

namespace Zielu92\FilamentImageLabeler\Tests;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;
use Zielu92\FilamentImageLabeler\Support\AnnotationColor;
use Zielu92\FilamentImageLabeler\Support\AnnotationSuggestion;
use Zielu92\FilamentImageLabeler\Support\FetchesImage;

class PlainAnnotatedModel extends Model
{
    use HasAnnotations;
}

class CustomAnnotatedModel extends Model
{
    use HasAnnotations;

    public function autoAnnotate(string $url, ?string $path): ?array
    {
        return [
            ['label' => 'USB Port', 'box' => [0.1, 0.2, 0.3, 0.4]],
        ];
    }
}

class AutoAnnotationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function shapeFrom(array $shapes): array
    {
        return $shapes[0];
    }

    protected function pathOf(array $shape): string
    {
        return $shape['target']['selector']['value'];
    }

    public function test_suggestion_requires_a_geometry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AnnotationSuggestion(label: 'x');
    }

    public function test_suggestion_validates_box_and_polygon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnnotationSuggestion(label: 'x', box: [0.1, 0.2, 0.3]);
    }

    public function test_suggestion_validates_polygon_points(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnnotationSuggestion(label: 'x', polygon: [[0, 0], [1, 1]]);
    }

    public function test_box_is_converted_to_a_closed_rect_path_in_pixels(): void
    {
        $shapes = AnnotationSuggestion::toShapes(
            [new AnnotationSuggestion(label: 'USB Port', box: [0.1, 0.2, 0.3, 0.4])],
            1000,
            500,
        );

        $this->assertCount(1, $shapes);
        $this->assertSame('USB Port', $shapes[0]['label']);
        $this->assertSame(AnnotationColor::forLabel('USB Port'), $shapes[0]['color']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $shapes[0]['id']
        );
        $this->assertSame('SvgSelector', $shapes[0]['target']['selector']['type']);
        $this->assertStringContainsString('M 100,100 L 400,100 L 400,300 L 100,300 Z', $this->pathOf($shapes[0]));
    }

    public function test_polygon_wins_over_box_and_is_clamped_to_the_image(): void
    {
        $shapes = AnnotationSuggestion::toShapes(
            [new AnnotationSuggestion(
                label: 'Chip',
                box: [0, 0, 0.1, 0.1],
                polygon: [[-0.5, 0.0], [1.5, 0.0], [0.5, 2.0]],
            )],
            200,
            100,
        );

        $this->assertStringContainsString('M 0,0 L 200,0 L 100,100 Z', $this->pathOf($this->shapeFrom($shapes)));
    }

    public function test_raw_arrays_are_accepted_as_suggestions(): void
    {
        $shapes = AnnotationSuggestion::toShapes(
            [['label' => 'Port', 'box' => [0, 0, 1, 1]]],
            50,
            50,
        );

        $this->assertStringContainsString('M 0,0 L 50,0 L 50,50 L 0,50 Z', $this->pathOf($this->shapeFrom($shapes)));
    }

    public function test_named_constructors_match_the_array_form(): void
    {
        $fromDto = AnnotationSuggestion::toShapes(
            [
                AnnotationSuggestion::box('Port', 0.5, 0.5, 0.25, 0.25),
                AnnotationSuggestion::polygon('Blob', [[0, 0], [1, 0], [0.5, 0.5]]),
            ],
            100,
            80,
        );
        $fromArray = AnnotationSuggestion::toShapes(
            [
                ['label' => 'Port', 'box' => [0.5, 0.5, 0.25, 0.25]],
                ['label' => 'Blob', 'polygon' => [[0, 0], [1, 0], [0.5, 0.5]]],
            ],
            100,
            80,
        );

        $this->assertSame(
            array_map([$this, 'pathOf'], $fromDto),
            array_map([$this, 'pathOf'], $fromArray),
        );
        $this->assertStringContainsString('M 50,40 L 75,40 L 75,60 L 50,60 Z', $this->pathOf($fromDto[0]));
        $this->assertStringContainsString('M 0,0 L 100,0 L 50,40 Z', $this->pathOf($fromDto[1]));
    }

    public function test_trait_default_disables_automatic_annotation(): void
    {
        $model = new PlainAnnotatedModel;

        $this->assertNull($model->autoAnnotate('https://example.com/a.png', null));
        $this->assertFalse($model->hasCustomAutoAnnotation());
        $this->assertTrue((new CustomAnnotatedModel)->hasCustomAutoAnnotation());
    }

    public function test_field_flags(): void
    {
        $field = ImageLabel::make('annotations')->enableAutoAnnotation(fn (): bool => true);

        $this->assertTrue($field->isAutoAnnotateEnabled());
        $this->assertFalse($field->isAutoAnnotateOnLoad());

        $field->autoAnnotateOnLoad();

        $this->assertTrue($field->isAutoAnnotateOnLoad());
        $this->assertFalse(ImageLabel::make('x')->isAutoAnnotateEnabled());
        $this->assertFalse(ImageLabel::make('x')->autoAnnotateOnLoad()->isAutoAnnotateOnLoad());
    }

    public function test_perform_returns_nothing_unless_enabled_overridden_and_editable(): void
    {
        $field = ImageLabel::make('annotations')->image(fn () => 'x.png');

        $this->assertSame([], $field->performAutoAnnotate(new CustomAnnotatedModel));

        $enabled = ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => 'x.png')
            ->readOnly();

        $this->assertSame([], $enabled->performAutoAnnotate(new CustomAnnotatedModel));

        $this->assertSame([], $enabled->enableAutoAnnotation(false)->performAutoAnnotate(new CustomAnnotatedModel));

        // enabled, not readOnly, but the model never overrode the hook:
        $this->assertSame([], ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => 'x.png')
            ->performAutoAnnotate(new PlainAnnotatedModel));
    }

    public function test_perform_converts_model_suggestions_over_the_real_image(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fil-ill-test-');
        $image = imagecreatetruecolor(300, 150);
        imagepng($image, $path);
        imagedestroy($image);

        $shapes = ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => $path)
            ->performAutoAnnotate(new CustomAnnotatedModel);

        @unlink($path);

        $this->assertCount(1, $shapes);
        $this->assertSame('USB Port', $this->shapeFrom($shapes)['label']);
        // box 0.1,0.2,0.3,0.4 over 300x150 = 30,30 → 120,90
        $this->assertStringContainsString('M 30,30 L 120,30 L 120,90 L 30,90 Z', $this->pathOf($this->shapeFrom($shapes)));
    }

    public function test_perform_throws_when_the_image_is_unreadable(): void
    {
        $this->expectException(RuntimeException::class);

        ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => '/does/not/exist.png')
            ->performAutoAnnotate(new CustomAnnotatedModel);
    }

    public function test_fetches_image_passes_files_through_and_rejects_other_schemes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fil-ill-test-');

        $this->assertSame($path, FetchesImage::localize($path));
        $this->assertNull(FetchesImage::localize('ftp://example.com/img.png'));
        $this->assertNull(FetchesImage::localize('not-an-image.png'));

        @unlink($path);
    }
}
