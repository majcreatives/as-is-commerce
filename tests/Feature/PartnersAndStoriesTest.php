<?php

declare(strict_types=1);

use App\Domain\Content\Exceptions\InvalidContentMedia;
use App\Domain\Content\Services\ContentMediaService;
use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use App\Livewire\Admin\Content\ContentManager;
use App\Livewire\Content\PartnerIndex;
use App\Livewire\Content\SuccessStoryIndex;
use App\Livewire\Marketplace\HomePartners;
use App\Livewire\Marketplace\HomeSuccessStories;
use App\Models\Partner;
use App\Models\Post;
use App\Models\SuccessStory;
use App\Models\User;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
 * Partners and success stories.
 *
 * The two rules most worth protecting are that ADDING IS NOT PUBLISHING and
 * that a record nobody has published cannot be read anywhere public. Both are
 * enforced by a scope rather than by remembering to filter, and both are
 * asserted here against every public surface that reads the table -- because a
 * scope that is bypassed by one page is not a scope.
 */
beforeEach(function (): void {
    seedPermissions();
});

/*
 * ---------------------------------------------------------------------------
 * Visibility
 * ---------------------------------------------------------------------------
 */

it('shows a published partner on the partners page', function (): void {
    Partner::factory()->create(['name' => 'Accra Freight Co', 'active' => true]);

    $this->get(route('partners.index'))
        ->assertOk()
        ->assertSee('Accra Freight Co');
});

it('does not show an unpublished partner anywhere public', function (): void {
    Partner::factory()->create(['name' => 'Draft Partner Ltd', 'active' => false]);

    // Every public surface that reads the table, not just the index page: a
    // draft leaking onto the homepage would be the actual failure.
    $this->get(route('partners.index'))->assertDontSee('Draft Partner Ltd');
    $this->get(route('home'))->assertDontSee('Draft Partner Ltd');
});

it('says so plainly when no partner has been published', function (): void {
    Partner::factory()->create(['active' => false]);

    $this->get(route('partners.index'))
        ->assertOk()
        ->assertSee('No partners listed yet');
});

it('shows a published story on the stories page', function (): void {
    SuccessStory::factory()->create([
        'name' => 'Ama Boateng',
        'quote' => 'I won a laptop on credits and paid the settlement amount.',
        'active' => true,
    ]);

    $this->get(route('success-stories.index'))
        ->assertOk()
        ->assertSee('Ama Boateng')
        ->assertSee('I won a laptop on credits');
});

it('does not show an unpublished story anywhere public', function (): void {
    SuccessStory::factory()->create(['name' => 'Draft Person', 'active' => false]);

    $this->get(route('success-stories.index'))->assertDontSee('Draft Person');
    $this->get(route('home'))->assertDontSee('Draft Person');
});

it('says so plainly when no story has been published', function (): void {
    $this->get(route('success-stories.index'))
        ->assertOk()
        ->assertSee('No stories published yet');
});

it('orders partners by the position an administrator set', function (): void {
    Partner::factory()->create(['name' => 'Third', 'sort_order' => 30, 'active' => true]);
    Partner::factory()->create(['name' => 'First', 'sort_order' => 10, 'active' => true]);
    Partner::factory()->create(['name' => 'Second', 'sort_order' => 20, 'active' => true]);

    $content = app(ContentDiscoveryQuery::class);

    expect($content->partners()->pluck('name')->all())->toBe(['First', 'Second', 'Third']);
});

/*
 * ---------------------------------------------------------------------------
 * Homepage blocks
 * ---------------------------------------------------------------------------
 */

it('hides the homepage sections when nothing is published', function (): void {
    Partner::factory()->create(['name' => 'Draft Partner', 'active' => false]);
    SuccessStory::factory()->create(['name' => 'Draft Person', 'active' => false, 'featured' => true]);
    Post::factory()->create(['title' => 'Draft Post', 'active' => false]);

    // The two blocks are Lazy components: on the home page they render only a
    // scroll-into-placeholder, so nothing about them appears in the initial
    // HTML at all. Assert the placeholder wiring is present (so a future edit
    // cannot silently re-eager-load the sections and start paying for the
    // queries again), but assert the real emptiness guarantee on the
    // components themselves.
    $response = $this->get(route('home'))->assertOk();

    expect($response->getContent())->toContain('__lazyLoad');

    // The SECTION copy must be absent, not just the records. Asserting on the
    // heading text would hit the footer link, which is on every page by design,
    // so this checks the subheading that exists only inside the section. A
    // "Success stories" heading over nothing reads as an absence of customers,
    // which is a claim about the business rather than about this page. With the
    // lazy loading turned off for the component under test, this decides
    // whether the block would draw anything were it actually reached.
    Livewire::withoutLazyLoading()->test(HomeSuccessStories::class)
        ->assertOk()
        ->assertDontSee('In their own words.')
        ->assertDontSee('All stories');

    Livewire::withoutLazyLoading()->test(HomePartners::class)
        ->assertOk()
        ->assertDontSee('Businesses that work with us.')
        ->assertDontSee('All partners');

    // The blog strip is not lazy: content is server-rendered for crawlers, so
    // the absence of any published post is still asserted against the page
    // itself, and the placeholders for the other two blocks must not have
    // leaked section copy in either.
    $response->assertDontSee('What is happening at As-Is, in plain words.');
    $response->assertDontSee('All posts');
    $response->assertDontSee('In their own words.');
    $response->assertDontSee('All stories');
    $response->assertDontSee('Businesses that work with us.');
    $response->assertDontSee('All partners');
});

it('shows a published partner on the homepage', function (): void {
    Partner::factory()->create(['name' => 'Published Partner', 'active' => true]);

    // The block is lazy: its content is fetched only when a visitor scrolls to
    // it, so the initial home page HTML holds the placeholder, not the strip.
    // The real contract lives in the component, which must draw the published
    // partner and the block's own heading once it is actually reached.
    Livewire::withoutLazyLoading()->test(HomePartners::class)
        ->assertOk()
        ->assertSee('Published Partner')
        ->assertSee('Businesses that work with us.')
        ->assertSee('All partners');
});

it('shows a featured published story on the homepage', function (): void {
    SuccessStory::factory()->create([
        'name' => 'Featured Person',
        'quote' => 'The auction channel is genuinely different.',
        'active' => true,
        'featured' => true,
    ]);

    Livewire::withoutLazyLoading()->test(HomeSuccessStories::class)
        ->assertOk()
        ->assertSee('Featured Person')
        ->assertSee('In their own words.')
        ->assertSee('All stories');
});

it('keeps a published story off the homepage until it is featured', function (): void {
    SuccessStory::factory()->create([
        'name' => 'Listed Only Person',
        'active' => true,
        'featured' => false,
    ]);

    // Lazy or not, the block is not reached for an unfeatured story: its own
    // query is what decides, and the query here is the contract under test.
    Livewire::withoutLazyLoading()->test(HomeSuccessStories::class)
        ->assertOk()
        ->assertDontSee('Listed Only Person')
        ->assertDontSee('In their own words.');

    // Still on its own page, which is the point of being published without
    // being featured.
    $this->get(route('success-stories.index'))
        ->assertOk()
        ->assertSee('Listed Only Person');
});

it('bounds what the homepage reads from each table', function (): void {
    Partner::factory()->count(9)->create(['active' => true]);
    SuccessStory::factory()->count(5)->create(['active' => true, 'featured' => true]);
    Post::factory()->count(10)->published()->create();

    $content = app(ContentDiscoveryQuery::class);

    expect($content->homepagePartners())->toHaveCount(6)
        ->and($content->homepageStories())->toHaveCount(3)
        ->and($content->homepagePosts())->toHaveCount(4);
});

/*
 * ---------------------------------------------------------------------------
 * Adding is not publishing
 * ---------------------------------------------------------------------------
 */

it('saves a new partner unpublished', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->set('name', 'Typed But Not Published')
        ->set('url', 'https://example.test')
        ->set('sortOrder', 5)
        ->call('save')
        ->assertHasNoErrors();

    $partner = Partner::firstWhere('name', 'Typed But Not Published');

    expect($partner)->not->toBeNull()
        ->and($partner->active)->toBeFalse();
});

it('saves a new story unpublished and unfeatured', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->call('create')
        ->set('name', 'Typed Person')
        ->set('storyTitle', 'Kumasi')
        ->set('quote', 'Something they actually said.')
        ->set('sortOrder', 1)
        ->call('save')
        ->assertHasNoErrors();

    $story = SuccessStory::firstWhere('name', 'Typed Person');

    expect($story)->not->toBeNull()
        ->and($story->active)->toBeFalse()
        ->and($story->featured)->toBeFalse();
});

it('publishes and unpublishes an existing record', function (): void {
    $admin = userWithRole('admin');
    $partner = Partner::factory()->inactive()->create(['name' => 'Ready Partner']);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('togglePublished', $partner->id)
        ->assertHasNoErrors();

    expect($partner->fresh()->active)->toBeTrue();

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('togglePublished', $partner->id);

    expect($partner->fresh()->active)->toBeFalse();

    // Unpublishing keeps the row. A record that was once public is not erased
    // by being retired.
    expect(Partner::whereKey($partner->id)->exists())->toBeTrue();
});

it('does not carry published state from one form open into the next', function (): void {
    $admin = userWithRole('admin');
    Partner::factory()->create(['name' => 'Already Published', 'active' => true]);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('edit', Partner::firstWhere('name', 'Already Published')->id)
        ->call('cancel')
        ->call('create')
        ->set('name', 'Second Partner')
        ->set('sortOrder', 2)
        ->call('save')
        ->assertHasNoErrors();

    // The bug this guards: `active` is not a form field, so opening the form for
    // a published record and then creating another must not publish it too.
    expect(Partner::firstWhere('name', 'Second Partner')->active)->toBeFalse();
});

/*
 * ---------------------------------------------------------------------------
 * Editing
 * ---------------------------------------------------------------------------
 */

it('updates an existing story and its featured flag', function (): void {
    $admin = userWithRole('admin');
    $story = SuccessStory::factory()->create([
        'name' => 'Before',
        'quote' => 'The old words.',
        'active' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->call('edit', $story->id)
        ->set('name', 'After')
        ->set('quote', 'The new words.')
        ->set('featured', true)
        ->call('save')
        ->assertHasNoErrors();

    $story->refresh();

    expect($story->name)->toBe('After')
        ->and($story->quote)->toBe('The new words.')
        ->and($story->featured)->toBeTrue()
        // Editing the words must not silently unpublish it.
        ->and($story->active)->toBeTrue();
});

it('rejects a partner record with a blank name', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->set('name', '')
        ->set('sortOrder', 1)
        ->call('save')
        ->assertHasErrors('name');

    expect(Partner::count())->toBe(0);
});

it('rejects a story with no quotation', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->call('create')
        ->set('name', 'Quiet Person')
        ->set('storyTitle', 'Accra')
        ->set('quote', '')
        ->set('sortOrder', 1)
        ->call('save')
        ->assertHasErrors('quote');

    expect(SuccessStory::count())->toBe(0);
});

it('rejects a partner website that is not a URL', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->set('name', 'Bad Link Co')
        ->set('url', 'not a url')
        ->set('sortOrder', 1)
        ->call('save')
        ->assertHasErrors('url');
});

/*
 * ---------------------------------------------------------------------------
 * Images
 * ---------------------------------------------------------------------------
 */

it('stores an uploaded logo and puts it on the public page', function (): void {
    Storage::fake('public');
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->set('name', 'Illustrated Co')
        ->set('sortOrder', 1)
        ->set('logo', TemporaryUploadedFile::fake()->image('logo.jpg', 200, 200))
        ->call('save')
        ->assertHasNoErrors();

    $partner = Partner::firstWhere('name', 'Illustrated Co');

    expect($partner->logo_path)->toStartWith('partners/');

    Storage::disk('public')->assertExists($partner->logo_path);
});

it('refuses an upload that is not an image', function (): void {
    Storage::fake('public');
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->set('name', 'Disguised Co')
        ->set('sortOrder', 1)
        ->set('logo', TemporaryUploadedFile::fake()->create('payload.php', 8, 'application/x-php'))
        ->call('save')
        ->assertHasErrors('logo');

    expect(Partner::count())->toBe(0);
});

it('refuses to delete a path outside the directories this service owns', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('products/1/someone-elses-image.jpg', 'x');

    app(ContentMediaService::class)->delete('products/1/someone-elses-image.jpg');

    // A hand-edited column must not turn "remove this logo" into "delete an
    // unrelated public file".
    Storage::disk('public')->assertExists('products/1/someone-elses-image.jpg');
});

it('refuses an oversized image inside the media service itself', function (): void {
    Storage::fake('public');

    $oversized = TemporaryUploadedFile::fake()
        ->create('logo.jpg', (ContentMediaService::MAX_KILOBYTES + 512), 'image/jpeg');

    // Called directly, with no Livewire form in front of it. The size limit is
    // currently a validation rule on the admin component, so it protects that
    // one caller and nothing else -- the service writes whatever it is handed.
    expect(fn () => app(ContentMediaService::class)->store($oversized, 'partners'))
        ->toThrow(InvalidContentMedia::class, '2 MB');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('keeps the component and the service agreed on the size limit', function (): void {
    $rules = (new ReflectionClass(ContentManager::class))
        ->getMethod('rules')
        ->invoke(new ContentManager);

    $for = static fn (string $tab, string $field): string => implode('|', (array) ($rules[$tab][$field] ?? []));

    // Three places state this number today. The component's rules are friendlier
    // to the person uploading; the service is what actually decides. If they
    // drift, the admin sees a message the service does not enforce. Pin them
    // together rather than trusting the copy to stay put.
    expect($for('partner', 'logo'))->toContain('max:'.ContentMediaService::MAX_KILOBYTES)
        ->and($for('story', 'photo'))->toContain('max:'.ContentMediaService::MAX_KILOBYTES)
        ->and($for('post', 'image'))->toContain('max:'.ContentMediaService::MAX_KILOBYTES);
});

it('removes an image and clears the reference', function (): void {
    Storage::fake('public');
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->set('name', 'Illustrated Co')
        ->set('sortOrder', 1)
        ->set('logo', TemporaryUploadedFile::fake()->image('logo.jpg', 200, 200))
        ->call('save');

    $partner = Partner::firstWhere('name', 'Illustrated Co');
    $path = $partner->logo_path;

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('removeImage', $partner->id)
        ->assertHasNoErrors();

    expect($partner->fresh()->logo_path)->toBeNull();

    Storage::disk('public')->assertMissing($path);
});

it('records an image removal in the activity log', function (): void {
    Storage::fake('public');
    $admin = userWithRole('admin');

    $partner = Partner::factory()->create(['logo_path' => 'partners/whatever.jpg']);
    Storage::disk('public')->put('partners/whatever.jpg', 'x');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('removeImage', $partner->id)
        ->assertHasNoErrors();

    // Creating and publishing a record are both logged, so an operator can
    // answer "who changed this". Removing the image is the one mutation on this
    // screen that left no trace, which is also the one nobody would think to ask
    // about afterwards.
    expect(Activity::query()
        ->where('description', 'image removed')
        ->where('subject_type', Partner::class)
        ->where('subject_id', $partner->id)
        ->exists()
    )->toBeTrue();
});

it('records an image removal on a story too', function (): void {
    Storage::fake('public');
    $admin = userWithRole('admin');

    $story = SuccessStory::factory()->create(['image_path' => 'success-stories/whatever.jpg']);
    Storage::disk('public')->put('success-stories/whatever.jpg', 'x');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->call('removeImage', $story->id)
        ->assertHasNoErrors();

    expect(Activity::query()
        ->where('description', 'image removed')
        ->where('subject_type', SuccessStory::class)
        ->where('subject_id', $story->id)
        ->exists()
    )->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 */

it('refuses the content screen to a guest and to a customer', function (): void {
    $this->get(route('admin.content'))->assertRedirect(route('login'));

    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->get(route('admin.content'))
        ->assertForbidden();
});

it('refuses a role holder who has neither view permission', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('partners.view');
    Role::findByName('admin')->revokePermissionTo('success_stories.view');
    Role::findByName('admin')->revokePermissionTo('posts.view');

    $this->actingAs($admin->fresh())
        ->get(route('admin.content'))
        ->assertForbidden();
});

it('opens the content screen to a role holder with one of the three permissions', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('success_stories.view');
    Role::findByName('admin')->revokePermissionTo('posts.view');

    // Either is a legitimate reason to be here; the screen opens and shows the
    // tab you may use. Asserted on the table's own columns rather than on the
    // word "stories", which appears in the page title and the shared footer.
    $this->actingAs($admin->fresh())
        ->get(route('admin.content'))
        ->assertOk()
        ->assertSee('Website')
        ->assertDontSee('What they said');
});

/*
 * The mirror of the test above, and the reason this pair exists at all.
 *
 * $tab is #[Url]-bound and defaults to 'partners', so mount() used to authorize
 * partners.view regardless of what else the person held. An admin holding only
 * the success_stories.* permissions passed the "either one will do" gate and
 * were then refused on the default tab -- handed a link by the nav that led to
 * a 403. Only the partner-only direction was covered before, which is exactly
 * why that survived review.
 */
it('opens the stories tab to a role holder who cannot see partners at all', function (): void {
    $admin = userWithRole('admin');
    foreach (['view', 'create', 'update', 'activate'] as $action) {
        Role::findByName('admin')->revokePermissionTo("partners.{$action}");
    }

    $this->actingAs($admin->fresh())
        ->get(route('admin.content'))
        ->assertOk()
        // The stories columns, not the partner ones.
        ->assertSee('What they said')
        ->assertDontSee('Website');
});

it('does not let the tab be set to something that is not a tab', function (): void {
    $admin = userWithRole('admin');

    // Unvalidated, an arbitrary value fell through to the success_stories
    // permissions simply by not being 'partners' -- so a URL decided which
    // permission set was checked.
    $this->actingAs($admin->fresh())
        ->get(route('admin.content', ['tab' => 'not-a-tab']))
        ->assertNotFound();
});

it('refuses the partner tab to someone holding only the story permissions', function (): void {
    $admin = userWithRole('admin');
    foreach (['view', 'create', 'update', 'activate'] as $action) {
        Role::findByName('admin')->revokePermissionTo("partners.{$action}");
    }

    $admin = $admin->fresh();

    // Mounted on the stories tab, which this administrator does hold, and then
    // asked for the partners one: the tab is refused on entry rather than
    // offered and failing on click.
    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->assertOk()
        ->call('switchTab', 'partners')
        ->assertForbidden();
});

it('does not let editing permission alone publish a record', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('partners.activate');

    $partner = Partner::factory()->inactive()->create();

    Livewire::actingAs($admin->fresh())
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('togglePublished', $partner->id)
        ->assertForbidden();

    // The record is untouched, not refused and then changed anyway.
    expect($partner->fresh()->active)->toBeFalse();
});

it('does not let publishing permission alone type a new record', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('partners.create');

    Livewire::actingAs($admin->fresh())
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('create')
        ->assertForbidden();

    expect(Partner::count())->toBe(0);
});

it('does not let a partner permission reach a success story', function (): void {
    $admin = userWithRole('admin');
    foreach (['create', 'update', 'activate'] as $action) {
        Role::findByName('admin')->revokePermissionTo("success_stories.{$action}");
    }

    $admin = $admin->fresh();

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->assertOk()
        ->call('create')
        ->assertForbidden();

    expect(SuccessStory::count())->toBe(0);
});

/*
 * ---------------------------------------------------------------------------
 * The public components
 * ---------------------------------------------------------------------------
 */

it('paginates the public listings rather than reading the whole table', function (): void {
    Partner::factory()->count(30)->create(['active' => true]);

    $paginator = Livewire::test(PartnerIndex::class)->viewData('partners');

    // The public listing is open to anyone, so it pages rather than reading the
    // table. 30 rows would all fit; 600 would not.
    expect($paginator->total())->toBe(30)
        ->and($paginator->perPage())->toBe(24);
});

it('paginates stories separately from partners', function (): void {
    SuccessStory::factory()->count(12)->create(['active' => true]);

    $paginator = Livewire::test(SuccessStoryIndex::class)->viewData('stories');

    expect($paginator->total())->toBe(12)
        ->and($paginator->perPage())->toBe(9);
});

/*
 * ---------------------------------------------------------------------------
 * Audit
 * ---------------------------------------------------------------------------
 */

it('records publishing in the activity log', function (): void {
    $admin = userWithRole('admin');
    $partner = Partner::factory()->inactive()->create();

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('togglePublished', $partner->id);

    expect(Activity::query()
        ->where('description', 'published')
        ->where('subject_type', Partner::class)
        ->where('subject_id', $partner->id)
        ->exists()
    )->toBeTrue();
});

it('records a story\'s featured flag in the log properties', function (): void {
    $admin = userWithRole('admin');
    $story = SuccessStory::factory()->create(['featured' => false]);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->call('edit', $story->id)
        ->set('featured', true)
        ->call('save');

    $entry = Activity::query()
        ->where('subject_type', SuccessStory::class)
        ->where('subject_id', $story->id)
        ->where('description', 'updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('featured'))->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Deleting
 * ---------------------------------------------------------------------------
 *
 * Delete is the permanent end of a content record's life: the row is gone and
 * the image file goes with it. It is gated on its own `*.delete` permission
 * (separate from `activate`, because deciding something should stop appearing
 * is not the same act as deciding it should cease to exist), and it is logged
 * while the row still exists so the audit has a record to point at.
 */

it('deletes a partner, its logo file and its row', function (): void {
    Storage::fake('public');

    $admin = userWithRole('admin');
    $partner = Partner::factory()->create([
        'name' => 'Doomed Partner',
        'logo_path' => 'partners/doomed.jpg',
    ]);
    Storage::disk('public')->put('partners/doomed.jpg', 'x');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('deleteRecord', $partner->id)
        ->assertHasNoErrors();

    expect(Partner::whereKey($partner->id)->doesntExist())->toBeTrue()
        ->and(Storage::disk('public')->missing('partners/doomed.jpg'))->toBeTrue();

    // Gone from the public partners page the moment the row is gone.
    $this->get(route('partners.index'))->assertDontSee('Doomed Partner');
});

it('deletes a success story, its file and its row', function (): void {
    Storage::fake('public');

    $admin = userWithRole('admin');
    $story = SuccessStory::factory()->create([
        'name' => 'Doomed Story',
        'image_path' => 'success-stories/doomed.jpg',
    ]);
    Storage::disk('public')->put('success-stories/doomed.jpg', 'x');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'stories'])
        ->call('deleteRecord', $story->id)
        ->assertHasNoErrors();

    expect(SuccessStory::whereKey($story->id)->doesntExist())->toBeTrue()
        ->and(Storage::disk('public')->missing('success-stories/doomed.jpg'))->toBeTrue();
});

it('leaves the label on the audit entry when a record is deleted', function (): void {
    $admin = userWithRole('admin');
    $partner = Partner::factory()->create(['name' => 'Logged Partner']);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('deleteRecord', $partner->id);

    $entry = Activity::query()
        ->where('description', 'deleted')
        ->where('subject_type', Partner::class)
        ->where('subject_id', $partner->id)
        ->first();

    // The deleted row is gone, so the log carries the name itself.
    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('label'))->toBe('Logged Partner');
});

it('refuses a delete without the tab\'s delete permission', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('partners.delete');
    $partner = Partner::factory()->create(['name' => 'Safe Partner']);

    Livewire::actingAs($admin->fresh())
        ->test(ContentManager::class, ['tab' => 'partners'])
        ->call('deleteRecord', $partner->id)
        ->assertForbidden();

    expect(Partner::whereKey($partner->id)->exists())->toBeTrue();
});
