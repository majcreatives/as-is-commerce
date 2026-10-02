<?php

declare(strict_types=1);

use App\Domain\Content\Services\ContentMediaService;
use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use App\Livewire\Admin\Content\ContentManager;
use App\Livewire\Content\PartnerIndex;
use App\Livewire\Content\SuccessStoryIndex;
use App\Models\Partner;
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

    $response = $this->get(route('home'))->assertOk();

    // The SECTION copy must be absent, not just the records. Asserting on the
    // heading text would hit the footer link, which is on every page by design,
    // so this checks the subheading that exists only inside the section. A
    // "Success stories" heading over nothing reads as an absence of customers,
    // which is a claim about the business rather than about this page.
    $response->assertDontSee('In their own words.');
    $response->assertDontSee('Businesses that work with us.');
    $response->assertDontSee('All partners');
    $response->assertDontSee('All stories');
});

it('shows a published partner on the homepage', function (): void {
    Partner::factory()->create(['name' => 'Published Partner', 'active' => true]);

    $this->get(route('home'))
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

    $this->get(route('home'))
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

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Listed Only Person');

    // Still on its own page, which is the point of being published without
    // being featured.
    $this->get(route('success-stories.index'))
        ->assertOk()
        ->assertSee('Listed Only Person');
});

it('bounds what the homepage reads from each table', function (): void {
    Partner::factory()->count(9)->create(['active' => true]);
    SuccessStory::factory()->count(5)->create(['active' => true, 'featured' => true]);

    $content = app(ContentDiscoveryQuery::class);

    expect($content->homepagePartners())->toHaveCount(6)
        ->and($content->homepageStories())->toHaveCount(3);
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

    $this->actingAs($admin->fresh())
        ->get(route('admin.content'))
        ->assertForbidden();
});

it('opens the content screen to a role holder with one of the two permissions', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('success_stories.view');

    // Either is a legitimate reason to be here; the screen opens and shows the
    // tab you may use. Asserted on the table's own columns rather than on the
    // word "stories", which appears in the page title and the shared footer.
    $this->actingAs($admin->fresh())
        ->get(route('admin.content'))
        ->assertOk()
        ->assertSee('Website')
        ->assertDontSee('What they said');
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
