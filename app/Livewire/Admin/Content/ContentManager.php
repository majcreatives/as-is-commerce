<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Content;

use App\Domain\Content\Services\ContentMediaService;
use App\Models\Partner;
use App\Models\SuccessStory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Partners and success stories, on one screen.
 *
 * They are kept together because they are the same kind of thing -- small
 * records an administrator publishes to the public site -- and splitting them
 * would mean two nearly identical screens. What differs is the fields, and the
 * tab decides which set applies.
 *
 * THE ADMIN AREA IS A CONTROL SURFACE, NOT A SECOND IMPLEMENTATION. These
 * records carry no money, no credits and no inventory, so the rules are simply:
 * every action is authorized on the permission for its own tab, the file write
 * goes through {@see ContentMediaService} rather than reaching for Storage
 * here, and every change is recorded in the activity log.
 *
 * THE TWO SETS OF PERMISSIONS ARE SEPARATE. Reaching this screen needs either
 * view permission, but `switchTab` re-checks the one for the tab being entered,
 * so holding `partners.manage` does not put a success story within reach.
 *
 * PUBLISHING IS NOT PART OF THE FORM. A record is always created unpublished --
 * `active` is not a form field at all -- and goes live through
 * {@see self::togglePublished()}, which is gated on a different permission from
 * editing. Two reasons: a person who can type a draft is not automatically the
 * person who decides to show it to customers, and a form that carried the flag
 * would let an edit republish a record nobody meant to bring back.
 *
 * NOTHING IS DELETED. `active` retires a record without destroying the row,
 * which is why a story that was once published keeps standing in the database.
 */
#[Layout('components.layouts.app')]
#[Title('Partners & stories')]
class ContentManager extends Component
{
    use WithFileUploads;

    #[Url]
    public string $tab = 'partners';

    public ?int $editingId = null;

    public bool $showForm = false;

    // Fields shared by both kinds of record.
    public string $name = '';

    public int $sortOrder = 0;

    // Partner fields.
    public string $url = '';

    public ?string $description = null;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    // Success story fields.
    public ?string $storyTitle = null;

    public string $quote = '';

    public bool $featured = false;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public function mount(): void
    {
        // At least one of the two. What is permitted once inside is decided per
        // tab by each action, not by this check.
        abort_unless(Gate::any(['partners.view', 'success_stories.view']), 403);

        $this->authorizeTab('view');
    }

    public function switchTab(string $tab): void
    {
        abort_unless(in_array($tab, ['partners', 'stories'], true), 404);

        $this->authorizeTab('view', $tab);

        $this->tab = $tab;
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->reset(
            'editingId',
            'name',
            'url',
            'description',
            'sortOrder',
            'storyTitle',
            'quote',
            'featured',
            'showForm',
            'logo',
            'photo',
        );
    }

    public function create(): void
    {
        $this->authorizeTab('create');

        $this->cancel();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeTab('update');

        if ($this->isPartners()) {
            $partner = Partner::findOrFail($id);
            $this->name = $partner->name;
            $this->url = $partner->url ?? '';
            $this->description = $partner->description;
            $this->sortOrder = $partner->sort_order;
        } else {
            $story = SuccessStory::findOrFail($id);
            $this->name = $story->name;
            $this->storyTitle = $story->title;
            $this->quote = $story->quote;
            $this->sortOrder = $story->sort_order;
            $this->featured = $story->featured;
        }

        $this->editingId = $id;
        $this->logo = null;
        $this->photo = null;
        $this->showForm = true;
    }

    public function save(ContentMediaService $media): void
    {
        $this->authorizeTab($this->editingId === null ? 'create' : 'update');

        $this->isPartners() ? $this->savePartner($media) : $this->saveStory($media);
    }

    /**
     * Publish or unpublish one record.
     *
     * Gated on `*.activate`, not on `*.update`: deciding that something goes in
     * front of customers is a different act from typing its fields, and a
     * narrower editorial role should be able to hold one without the other.
     */
    public function togglePublished(int $id): void
    {
        $this->authorizeTab('activate');

        $record = $this->isPartners() ? Partner::findOrFail($id) : SuccessStory::findOrFail($id);
        $publishing = ! $record->active;

        $record->active = $publishing;
        $record->updated_by = auth()->id();
        $record->save();

        activity('content')
            ->performedOn($record)
            ->causedBy(auth()->user())
            ->withProperties(['active' => $publishing])
            ->log($publishing ? 'published' : 'unpublished');

        session()->flash('status', $publishing ? 'Published.' : 'Unpublished.');
    }

    /**
     * Remove the image on a saved record.
     *
     * This is the file on disk and the column together. Removing an image is
     * not publishing a record, so it sits behind `update`.
     */
    public function removeImage(int $id, ContentMediaService $media): void
    {
        $this->authorizeTab('update');

        if ($this->isPartners()) {
            $partner = Partner::findOrFail($id);
            $media->delete($partner->logo_path);
            $partner->logo_path = null;
            $partner->updated_by = auth()->id();
            $partner->save();
        } else {
            $story = SuccessStory::findOrFail($id);
            $media->delete($story->image_path);
            $story->image_path = null;
            $story->updated_by = auth()->id();
            $story->save();
        }

        session()->flash('status', 'Image removed.');
    }

    /**
     * The tab's records, in display order.
     *
     * Not paginated: these tables hold a handful of rows and the screen has to
     * show all of them at once for `sort_order` to mean anything. The public
     * pages, which are open to anyone, are the ones that paginate.
     *
     * @return Collection<int, Partner>|Collection<int, SuccessStory>
     */
    public function records(): Collection
    {
        return $this->isPartners()
            ? Partner::query()->orderBy('sort_order')->orderBy('id')->get()
            : SuccessStory::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function can(string $action): bool
    {
        return Gate::check($this->isPartners() ? "partners.{$action}" : "success_stories.{$action}");
    }

    public function render(): View
    {
        $records = $this->records();

        return view('livewire.admin.content.content-manager', [
            'records' => $records,
        ]);
    }

    private function savePartner(ContentMediaService $media): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'url' => ['nullable', 'string', 'max:500', 'url'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
            'logo' => ['nullable', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp,gif'],
        ], [], [
            'sortOrder' => 'position',
            'logo' => 'logo',
        ]);

        $partner = $this->editingId === null ? new Partner : Partner::findOrFail($this->editingId);

        $partner->fill([
            'name' => $validated['name'],
            'url' => $validated['url'] !== '' ? $validated['url'] : null,
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'sort_order' => $validated['sortOrder'],
        ]);

        if (! $partner->exists) {
            // Unpublished, always. Publishing is a separate authorized act.
            $partner->active = false;
            $partner->created_by = auth()->id();
        }

        $partner->updated_by = auth()->id();

        $this->replaceImage(
            $partner,
            'logo_path',
            $this->logo,
            $media,
            'partners',
        );

        $this->record($partner, 'created', 'updated');

        $this->cancel();
        session()->flash('status', 'Partner saved. Publish it when it is ready.');
    }

    private function saveStory(ContentMediaService $media): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'storyTitle' => ['required', 'string', 'max:160'],
            'quote' => ['required', 'string', 'max:1200'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
            'photo' => ['nullable', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp,gif'],
        ], [], [
            'storyTitle' => 'title',
            'sortOrder' => 'position',
            'photo' => 'photo',
        ]);

        $story = $this->editingId === null ? new SuccessStory : SuccessStory::findOrFail($this->editingId);

        $story->fill([
            'name' => $validated['name'],
            'title' => $validated['storyTitle'],
            'quote' => $validated['quote'],
            'sort_order' => $validated['sortOrder'],
        ]);

        if (! $story->exists) {
            $story->active = false;
            // A new story is not promoted either: `featured` is a decision, and
            // an unchecked box that silently defaulted to true would put it on
            // the front page without anybody choosing to.
            $story->featured = false;
            $story->created_by = auth()->id();
        } else {
            $story->featured = (bool) $this->featured;
        }

        $story->updated_by = auth()->id();

        $this->replaceImage(
            $story,
            'image_path',
            $this->photo,
            $media,
            'success-stories',
        );

        $this->record($story, 'created', 'updated');

        $this->cancel();
        session()->flash('status', 'Success story saved. Publish it when it is ready.');
    }

    /**
     * Swap a record's image for a newly uploaded one, or leave it alone.
     *
     * ORDER MATTERS: the new file is stored first, and the old one is only
     * deleted once the row points at the new path. A store that throws leaves
     * the record still holding the image it had, rather than holding neither.
     *
     * @param  'logo_path'|'image_path'  $column
     */
    private function replaceImage(
        Partner|SuccessStory $model,
        string $column,
        ?TemporaryUploadedFile $upload,
        ContentMediaService $media,
        string $directory,
    ): void {
        if ($upload === null) {
            $model->save();

            return;
        }

        $replaced = $model->{$column};
        $model->{$column} = $media->store($upload, $directory);
        $model->save();

        $media->delete($replaced);
    }

    /**
     * Write the activity entry for a saved record.
     *
     * `featured` goes in `properties` rather than being left to the model diff:
     * it is a business property of the record, and it decides whether a story
     * appears on the front page.
     */
    private function record(Partner|SuccessStory $model, string $created, string $updated): void
    {
        $properties = $model instanceof SuccessStory
            ? ['featured' => $model->featured]
            : [];

        activity('content')
            ->performedOn($model)
            ->causedBy(auth()->user())
            ->withProperties($properties)
            ->log($model->wasRecentlyCreated ? $created : $updated);
    }

    /**
     * Authorize against the permission for the tab in play.
     */
    private function authorizeTab(string $action, ?string $tab = null): void
    {
        $this->authorize(
            ($tab ?? $this->tab) === 'partners'
                ? "partners.{$action}"
                : "success_stories.{$action}",
        );
    }

    private function isPartners(): bool
    {
        return $this->tab === 'partners';
    }
}
