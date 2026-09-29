<?php

namespace Zielu92\FilamentImageLabeler\Tests;

use Illuminate\Database\Eloquent\Model;
use Zielu92\FilamentImageLabeler\Concerns\HasAnnotations;
use Zielu92\FilamentImageLabeler\Forms\Components\ImageLabel;
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

class RecordingAnnotatedModel extends Model
{
    use HasAnnotations;

    public ?string $seenUrl = null;

    public ?string $seenPath = null;

    public function autoAnnotate(string $url, ?string $path): ?array
    {
        $this->seenUrl = $url;
        $this->seenPath = $path;

        return [];
    }
}

class AutoAnnotationTest extends TestCase
{
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

    public function test_box_and_polygon_survive_to_pending_intact(): void
    {
        $pending = AnnotationSuggestion::toPending([
            new AnnotationSuggestion(label: 'USB Port', box: [0.1, 0.2, 0.3, 0.4]),
        ]);

        $this->assertCount(1, $pending);
        $this->assertSame('USB Port', $pending[0]['label']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $pending[0]['id']
        );
        $this->assertSame([0.1, 0.2, 0.3, 0.4], $pending[0]['rect']);
        $this->assertNull($pending[0]['polygon']);
    }

    public function test_polygon_wins_over_box_and_points_are_clamped(): void
    {
        $pending = AnnotationSuggestion::toPending([
            new AnnotationSuggestion(
                label: 'Chip',
                box: [0, 0, 0.1, 0.1],
                polygon: [[-0.5, 0.0], [1.5, 0.0], [0.5, 2.0]],
            ),
        ]);

        $this->assertNull($pending[0]['rect']);
        $this->assertSame([[0.0, 0.0], [1.0, 0.0], [0.5, 1.0]], $pending[0]['polygon']);
    }

    public function test_named_constructors_match_the_array_form(): void
    {
        $fromDto = AnnotationSuggestion::toPending([
            AnnotationSuggestion::box('Port', 0.5, 0.5, 0.25, 0.25),
            AnnotationSuggestion::polygon('Blob', [[0, 0], [1, 0], [0.5, 0.5]]),
        ]);
        $fromArray = AnnotationSuggestion::toPending([
            ['label' => 'Port', 'box' => [0.5, 0.5, 0.25, 0.25]],
            ['label' => 'Blob', 'polygon' => [[0, 0], [1, 0], [0.5, 0.5]]],
        ]);

        unset($fromDto[0]['id'], $fromDto[1]['id'], $fromArray[0]['id'], $fromArray[1]['id']);

        $this->assertSame($fromArray, $fromDto);
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
        $this->assertTrue($field->showsAutoAnnotateButton());
        $this->assertFalse($field->autoAnnotateButton(false)->showsAutoAnnotateButton());
        $this->assertTrue($field->autoAnnotateOnLoad()->isAutoAnnotateOnLoad());
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

    public function test_annotate_button_appearance(): void
    {
        $field = ImageLabel::make('x')->enableAutoAnnotation();

        $this->assertSame('Annotate', $field->getAutoAnnotateButtonLabel());
        $this->assertSame('heroicon-m-sparkles', $field->getAutoAnnotateButtonIcon());

        $field->autoAnnotateButtonLabel(fn () => 'Detect')->autoAnnotateButtonIcon(null);

        $this->assertSame('Detect', $field->getAutoAnnotateButtonLabel());
        $this->assertNull($field->getAutoAnnotateButtonIcon());
    }

    public function test_perform_returns_pending_suggestions(): void
    {
        $pending = ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => 'x.png')
            ->performAutoAnnotate(new CustomAnnotatedModel);

        $this->assertCount(1, $pending);
        $this->assertSame('USB Port', $pending[0]['label']);
        $this->assertSame([0.1, 0.2, 0.3, 0.4], $pending[0]['rect']);
    }

    public function test_perform_hands_the_model_a_path_when_the_url_is_readable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fil-ill-test-');

        $model = new RecordingAnnotatedModel;
        ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => $path)
            ->performAutoAnnotate($model);

        $this->assertSame($path, $model->seenUrl);
        $this->assertSame($path, $model->seenPath);

        $model = new RecordingAnnotatedModel;
        $this->app['config']->set('app.url', 'http://localhost');
        ImageLabel::make('annotations')
            ->enableAutoAnnotation()
            ->image(fn () => 'http://localhost/livewire/preview-file/tmp-upload.png?expires=1&signature=x')
            ->performAutoAnnotate($model);

        // same-host protected URLs are NEVER fetched from ourselves
        $this->assertSame('http://localhost/livewire/preview-file/tmp-upload.png?expires=1&signature=x', $model->seenUrl);
        $this->assertNull($model->seenPath);

        @unlink($path);
    }

    public function test_fetches_image_rules(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fil-ill-test-');

        $this->assertSame($path, FetchesImage::localize($path));
        $this->assertNull(FetchesImage::localize('ftp://example.com/img.png'));
        $this->assertNull(FetchesImage::localize('not-an-image.png'));
        $this->assertNull(FetchesImage::localize('http://localhost/app.png'));

        @mkdir(public_path('storage'), recursive: true);
        $public = public_path('storage/fil-ill-map.png');
        file_put_contents($public, 'x');
        $this->app['config']->set('app.url', 'http://localhost');

        $this->assertSame(realpath($public), FetchesImage::localize('http://localhost/storage/fil-ill-map.png'));
        $this->assertNull(FetchesImage::localize('http://localhost/storage/missing.png'));

        @unlink($public);
        @unlink($path);
    }
}
