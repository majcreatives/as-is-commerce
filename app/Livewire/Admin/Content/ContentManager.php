<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Content;

use App\Domain\Content\Services\ContentMediaService;
use App\Models\BlogCategory;
use App\Models\Partner;
use App\Models\Post;
use App\Models\SuccessStory;
use App\Models\Tag;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Partners, success stories and blog posts, on one screen.
 *
 * They are kept together because they are the same kind of thing -- small
 * records an administrator publishes to the public site -- and splitting them
 * would mean several nearly identical screens. What differs is the fields, and
 * the tab decides which set applies.
 *
 * THE ADMIN AREA IS A CONTROL SURFACE, NOT A SECOND IMPLEMENTATION. These
 * records carry no money, no credits and no inventory, so the rules are simply:
 * every action is authorized on the permission for its own tab, the file write
 * goes through {@see ContentMediaService} rather than reaching for Storage
 * here, and every change is recorded in the activity log.
 *
 * THE SETS OF PERMISSIONS ARE SEPARATE. Reaching this screen needs one of the
 * view permissions, but `switchTab` re-checks the one for the tab being
 * entered, so holding `partners.manage` does not put a success story or a blog
 * post within reach.
 *
 * PUBLISHING IS NOT PART OF THE FORM. A record is always created unpublished --
 * `active` is not a form field at all -- and goes live through
 * {@see self::togglePublished()}, which is gated on a different permission from
 * editing. Two reasons: a person who can type a draft is not automatically the
 * person who decides to show it to customers, and a form that carried the flag
 * would let an edit republish a record nobody meant to bring back.
 *
 * DELETE IS REAL, ARCHIVE IS THE SOFT OPTION. `active = false` retires a record
 * without destroying the row -- a story that was once published keeps standing
 * in the database. `delete` is the opposite end: a permanent removal, gated on
 * its own `*.delete` permission, image file and row together, logged in the
 * activity audit. Unpublished records are the intended delete candidates; a
 * published post that is deleted leaves the site and the sitemap immediately,
 * which is what "permanent" means.
 *
 * BLOG POSTS ARE NOT A CATALOGUE. A post has a title, a slug and a body rather
 * than a position to sort into: the public blog lists by publish date, newest
 * first, so `sort_order` does not exist here at all.
 */
#[Layout('components.layouts.app')]
#[Title('Partners, stories & posts')]
class ContentManager extends Component
{
    use WithFileUploads;

    /**
     * The tab names, and the permission prefix each one answers to.
     *
     * The three vocabularies deliberately do not match: the stories tab is
     * "stories" in the URL and "success_stories." in the permission set, and
     * the posts tab is "posts" both ways. Written out once here rather than
     * reassembled at each check, because deriving one from the other is how a
     * tab ends up authorizing the wrong thing.
     *
     * @var array<string, string>
     */
    private const TAB_PERMISSION_PREFIX = [
        'partners' => 'partners',
        'stories' => 'success_stories',
        'posts' => 'posts',
    ];

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

    // Blog post fields. A post has no sort order -- the public blog lists by
    // publish date, newest first -- so these are the fields and nothing else.
    public string $postTitle = '';

    public string $slug = '';

    public ?string $excerpt = null;

    public string $body = '';

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    // Blog post taxonomy. A post belongs to at most one category and may carry
    // several existing tags; the tags are picked, never typed.
    public ?int $categoryId = null;

    /** @var list<int> */
    public array $tags = [];

    // Blog post search fields. `secondaryKeywords` is the form's comma-separated
    // text; it is stored as a JSON list on the row.
    public ?string $metaTitle = null;

    public ?string $metaDescription = null;

    public ?string $primaryKeyword = null;

    public string $secondaryKeywords = '';

    public function mount(): void
    {
        // $tab is bound from the query string, so it cannot be assumed to be one
        // of the two. Validated here and not only in switchTab() because mount()
        // is what decides which permission set opens the screen -- an unchecked
        // value reaching authorizeTab() falls through to the stories permissions
        // simply by not being 'partners'.
        abort_unless(array_key_exists($this->tab, self::TAB_PERMISSION_PREFIX), 404);

        // WHICH TABS THIS PERSON CAN SEE. Checked rather than assumed, so the
        // screen opens on a tab that is actually permitted instead of refusing a
        // request the nav has already offered: the nav links here on the strength
        // of EITHER view permission, so someone who manages only one of the two
        // would otherwise be handed a link that leads to a 403.
        $viewable = array_values(array_filter(
            array_keys(self::TAB_PERMISSION_PREFIX),
            fn (string $tab): bool => Gate::check(self::TAB_PERMISSION_PREFIX[$tab].'.view'),
        ));

        abort_if($viewable === [], 403);

        if (! in_array($this->tab, $viewable, true)) {
            $this->tab = $viewable[0];
        }
    }

    public function switchTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TAB_PERMISSION_PREFIX), 404);

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
            'postTitle',
            'slug',
            'excerpt',
            'body',
            'categoryId',
            'tags',
            'metaTitle',
            'metaDescription',
            'primaryKeyword',
            'secondaryKeywords',
            'showForm',
            'logo',
            'photo',
            'image',
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
        } elseif ($this->isStories()) {
            $story = SuccessStory::findOrFail($id);
            $this->name = $story->name;
            $this->storyTitle = $story->title;
            $this->quote = $story->quote;
            $this->sortOrder = $story->sort_order;
            $this->featured = $story->featured;
        } else {
            $post = Post::findOrFail($id);
            $this->postTitle = $post->title;
            $this->slug = $post->slug;
            $this->excerpt = $post->excerpt;
            $this->body = $post->body;
            $this->categoryId = $post->category_id;
            $this->tags = $post->tags()->pluck('tags.id')->all();
            $this->metaTitle = $post->meta_title;
            $this->metaDescription = $post->meta_description;
            $this->primaryKeyword = $post->primary_keyword;
            $this->secondaryKeywords = implode(', ', $post->secondary_keywords ?? []);
        }

        $this->editingId = $id;
        $this->logo = null;
        $this->photo = null;
        $this->image = null;
        $this->showForm = true;
    }

    public function save(ContentMediaService $media): void
    {
        $this->authorizeTab($this->editingId === null ? 'create' : 'update');

        if ($this->isPartners()) {
            $this->savePartner($media);
        } elseif ($this->isStories()) {
            $this->saveStory($media);
        } else {
            $this->savePost($media);
        }
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

        $record = match ($this->tab) {
            'partners' => Partner::findOrFail($id),
            'stories' => SuccessStory::findOrFail($id),
            default => Post::findOrFail($id),
        };

        $publishing = ! $record->active;

        $record->active = $publishing;

        // A post is public only when it is both active and dated. Publishing a
        // post that has never been published stamps the date it went live, so a
        // freshly published post is immediately readable rather than public-but-
        // undated. Republishing an already-dated post keeps its original date.
        if ($record instanceof Post && $publishing && $record->published_at === null) {
            $record->published_at = now();
        }

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
     *
     * Logged, because creating and publishing a record both are. A logo or
     * photograph can disappear from a page someone is responsible for, and
     * "who removed this" is exactly the question asked afterwards and answered
     * badly when the answer is "nothing recorded that".
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

            $record = $partner;
        } elseif ($this->isStories()) {
            $story = SuccessStory::findOrFail($id);
            $media->delete($story->image_path);
            $story->image_path = null;
            $story->updated_by = auth()->id();
            $story->save();

            $record = $story;
        } else {
            $post = Post::findOrFail($id);
            $media->delete($post->image_path);
            $post->image_path = null;
            $post->updated_by = auth()->id();
            $post->save();

            $record = $post;
        }

        activity('content')
            ->performedOn($record)
            ->causedBy(auth()->user())
            ->log('image removed');

        session()->flash('status', 'Image removed.');
    }

    /**
     * Permanently delete one record of the current tab.
     *
     * This is the destructive end of the lifecycle and it is gated on its own
     * `*.delete` permission, separate from `archive`: deciding that a record
     * should stop appearing is different from deciding it should cease to
     * exist. The image file and the row go together, and the activity log keeps
     * the `deleted` entry (logged while the row still exists so the audit has a
     * record to point at). Unpublishing first is not required -- a mistaken
     * active record can be deleted outright -- but the confirmation dialog is
     * the moment that decision is made deliberately.
     */
    public function delete(int $id, ContentMediaService $media): void
    {
        $this->authorizeTab('delete');

        $record = match ($this->tab) {
            'partners' => Partner::findOrFail($id),
            'stories' => SuccessStory::findOrFail($id),
            default => Post::findOrFail($id),
        };

        $image = match ($this->tab) {
            'partners' => $record->logo_path,
            default => $record->image_path,
        };
        $label = $this->deletableLabel($record);

        activity('content')
            ->performedOn($record)
            ->causedBy(auth()->user())
            ->withProperties(['label' => $label])
            ->log('deleted');

        $record->delete();

        // The file delete happens after the row is gone so a failure to remove
        // the image does not leave the record half-deleted in the database.
        // ContentMediaService::delete() already ignores null and any path it
        // did not write itself.
        $media->delete($image);

        session()->flash('status', 'Deleted permanently.');
    }

    /**
     * A short human name for the audit entry, since the deleted row is gone
     * the moment after the log was written.
     */
    private function deletableLabel(Partner|SuccessStory|Post $record): string
    {
        if ($record instanceof Partner) {
            return $record->name;
        }

        if ($record instanceof SuccessStory) {
            return $record->name;
        }

        return $record->title;
    }

    /**
     * The tab's records, in display order.
     *
     * Not paginated: these tables hold a handful of rows and the screen has to
     * show all of them at once for `sort_order` to mean anything. The public
     * pages, which are open to anyone, are the ones that paginate.
     *
     * Posts have no sort order -- the public blog lists by publish date, newest
     * first -- so the posts tab orders by that instead.
     *
     * @return Collection<int, Partner>|Collection<int, SuccessStory>|Collection<int, Post>
     */
    public function records(): Collection
    {
        return match ($this->tab) {
            'partners' => Partner::query()->orderBy('sort_order')->orderBy('id')->get(),
            'stories' => SuccessStory::query()->orderBy('sort_order')->orderBy('id')->get(),
            default => Post::query()->orderByDesc('published_at')->orderByDesc('id')->get(),
        };
    }

    public function can(string $action): bool
    {
        return Gate::check($this->permissionPrefix().".{$action}");
    }

    public function render(): View
    {
        $records = $this->records();

        return view('livewire.admin.content.content-manager', [
            'records' => $records,
            'categoryOptions' => BlogCategory::query()->active()->orderBy('name')->get(['id', 'name']),
            'tagOptions' => Tag::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The validation rules for both forms.
     *
     * Public and gathered in one place so the image size limit is stated once.
     * The number itself belongs to the media service, which is what actually
     * enforces it -- these rules only decide what the person uploading is told
     * before that happens.
     *
     * @return array<string, array<string, list<string|Exists>>>
     */
    public function rules(): array
    {
        $image = ['nullable', 'image', 'max:'.ContentMediaService::MAX_KILOBYTES, 'mimes:jpg,jpeg,png,webp,gif'];

        return [
            'partner' => [
                'name' => ['required', 'string', 'max:160'],
                'url' => ['nullable', 'string', 'max:500', 'url'],
                'description' => ['nullable', 'string', 'max:1000'],
                'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
                'logo' => $image,
            ],
            'story' => [
                'name' => ['required', 'string', 'max:160'],
                'storyTitle' => ['required', 'string', 'max:160'],
                'quote' => ['required', 'string', 'max:1200'],
                'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
                'photo' => $image,
            ],
            'post' => [
                'postTitle' => ['required', 'string', 'max:150'],
                'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'excerpt' => ['nullable', 'string', 'max:250'],
                'body' => ['required', 'string', 'max:20000'],
                'categoryId' => ['nullable', 'integer', Rule::exists('blog_categories', 'id')],
                'tags' => ['array', 'max:10'],
                'tags.*' => ['integer', Rule::exists('tags', 'id')],
                'metaTitle' => ['nullable', 'string', 'max:150'],
                'metaDescription' => ['nullable', 'string', 'max:300'],
                'primaryKeyword' => ['nullable', 'string', 'max:100'],
                'secondaryKeywords' => ['nullable', 'string', 'max:500'],
                'image' => $image,
            ],
        ];
    }

    private function savePartner(ContentMediaService $media): void
    {
        $validated = $this->validate(
            $this->rules()['partner'],
            [],
            [
                'sortOrder' => 'position',
                'logo' => 'logo',
            ],
        );

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
        $validated = $this->validate(
            $this->rules()['story'],
            [],
            [
                'storyTitle' => 'title',
                'sortOrder' => 'position',
                'photo' => 'photo',
            ],
        );

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

    private function savePost(ContentMediaService $media): void
    {
        $validated = $this->validate(
            $this->rules()['post'],
            [],
            [
                'postTitle' => 'title',
                'slug' => 'slug',
                'excerpt' => 'excerpt',
                'body' => 'body',
                'categoryId' => 'category',
                'metaTitle' => 'meta title',
                'metaDescription' => 'meta description',
                'primaryKeyword' => 'primary keyword',
                'secondaryKeywords' => 'secondary keywords',
                'image' => 'image',
            ],
        );

        $post = $this->editingId === null ? new Post : Post::findOrFail($this->editingId);

        if (! $post->exists || $post->slug !== $validated['slug']) {
            $this->assertSlugIsFree($validated['slug'], $post->id);
        }

        $post->fill([
            'title' => $validated['postTitle'],
            'slug' => $validated['slug'],
            'excerpt' => $validated['excerpt'] !== '' ? $validated['excerpt'] : null,
            // The body is rich text and it is rendered with {!! !!}, so nothing
            // reaches the column except through the whitelist.
            'body' => RichText::clean($validated['body']),
            'category_id' => $validated['categoryId'],
            'meta_title' => $validated['metaTitle'],
            'meta_description' => $validated['metaDescription'],
            'primary_keyword' => $validated['primaryKeyword'],
            'secondary_keywords' => $this->keywordsList($validated['secondaryKeywords']),
        ]);

        if (! $post->exists) {
            // Unpublished, always. Publishing is a separate authorized act. The
            // slug check above guards the row being edited too, but it also
            // runs here on create, because the database backstop is a crash,
            // not a message.
            $post->active = false;
            $post->published_at = null;
            $post->created_by = auth()->id();
        }

        $post->updated_by = auth()->id();

        $this->replaceImage(
            $post,
            'image_path',
            $this->image,
            $media,
            'posts',
        );

        // Tags are a link on a real row, so they are synced after the post has
        // been persisted (the pivot needs the id on a fresh create). The rule
        // above has already confined the selection to rows that exist.
        $post->tags()->sync($validated['tags']);

        $this->record($post, 'created', 'updated');

        $this->cancel();
        session()->flash('status', 'Blog post saved. Publish it when it is ready.');
    }

    /**
     * Split the form's comma-separated keyword text into a clean JSON list.
     *
     * Empty input becomes null so the row, the meta tag and the JSON-LD all
     * agree that no keywords were supplied. A repeated keyword is not stored
     * twice, and a whitespace-only fragment is dropped rather than persisted.
     *
     * @return list<string>|null
     */
    private function keywordsList(string $input): ?array
    {
        $keywords = array_values(array_filter(
            array_map(
                static fn (string $keyword): string => trim($keyword),
                explode(',', $input),
            ),
            static fn (string $keyword): bool => $keyword !== '',
        ));

        $keywords = array_values(array_unique($keywords));

        return $keywords === [] ? null : $keywords;
    }

    private function assertSlugIsFree(string $slug, ?int $ignoringId): void
    {
        $query = Post::query()->where('slug', $slug);

        if ($ignoringId !== null) {
            $query->whereKeyNot($ignoringId);
        }

        if ($query->exists()) {
            $this->addError('slug', 'That slug is already in use by another post.');

            throw ValidationException::withMessages(['slug' => 'That slug is already in use by another post.']);
        }
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
        Partner|SuccessStory|Post $model,
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
    private function record(Partner|SuccessStory|Post $model, string $created, string $updated): void
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
        $this->authorize(self::TAB_PERMISSION_PREFIX[$tab ?? $this->tab].".$action");
    }

    /**
     * The permission set the current tab answers to.
     */
    private function permissionPrefix(): string
    {
        return self::TAB_PERMISSION_PREFIX[$this->tab];
    }

    private function isPartners(): bool
    {
        return $this->tab === 'partners';
    }

    private function isStories(): bool
    {
        return $this->tab === 'stories';
    }
}
