<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

// The packet reads the operator identity, so the settings have to exist for the
// "is anything still provisional" logic on the cover to mean anything. The
// command is also run once here, so the tests that only read the output are
// asserting against a packet that was genuinely produced rather than a
// directory that happens to exist.
beforeEach(function (): void {
    seedSettings();

    File::deleteDirectory(packetPath());

    artisan('legal:review-packet', ['--path' => packetPath()]);
});

/*
 * The legal review packet.
 *
 * This exists because the pages are written but cannot be sent anywhere: the
 * domain does not exist, production is unapproved, and a lawyer cannot review
 * a Blade template. So the whole point of the command is that the files it
 * writes are real, complete, and readable by somebody who has never heard of
 * this repository.
 *
 * The first version of this command silently did not exist. Laravel's command
 * loader catches every throwable while scanning a directory and moves on, so a
 * missing `use Illuminate\Console\Command;` produced no error anywhere, just a
 * command that was not there. Hence the registration test below, which would
 * have caught it in a second.
 */

it('is registered as an artisan command', function (): void {
    // Guards the whole file, because a command that fails to register is
    // invisible rather than broken.
    expect(array_keys(Artisan::all()))->toContain('legal:review-packet');
});

it('writes a cover and one self-contained page per legal document', function (): void {
    $path = packetPath();

    expect(File::exists("{$path}/index.html"))->toBeTrue()
        ->and(File::exists("{$path}/privacy.html"))->toBeTrue()
        ->and(File::exists("{$path}/terms.html"))->toBeTrue()
        ->and(File::exists("{$path}/cookies.html"))->toBeTrue();
});

it('inlines the stylesheet so a page needs no build directory and no server', function (): void {
    $html = File::get(packetPath().'/privacy.html');

    // A packet that still links to /build/assets is a packet that renders
    // unstyled the moment it leaves this machine, which is the one thing it
    // must not do.
    expect($html)->toContain('<style>')
        ->and($html)->not->toContain('/build/assets/');
});

it('carries no scripts, because a legal document should not execute anything', function (): void {
    $html = File::get(packetPath().'/terms.html');

    expect(stripos($html, '<script'))->toBeFalse();
});

it('links the three legal pages to each other rather than to a dead host', function (): void {
    $html = File::get(packetPath().'/privacy.html');

    expect($html)->toContain('href="terms.html"')
        ->and($html)->toContain('href="privacy.html"');
});

it('marks every page as a draft, on its face', function (): void {
    // An unmarked draft is worse than no draft, because it can be mistaken for
    // the finished article. The marker has to survive into the file itself, not
    // live only on a cover that may never be opened.
    foreach (['privacy', 'terms', 'cookies'] as $slug) {
        expect(File::get(packetPath()."/{$slug}.html"))
            ->toContain('DRAFT')
            ->toContain('NOT LEGAL ADVICE');
    }
});

it('carries the real privacy and terms text, not a summary of it', function (): void {
    // The value of handing over a render rather than writing a document is that
    // the words are the words. If this ever passes on a stub, the review is of
    // something nobody is actually publishing.
    $privacy = File::get(packetPath().'/privacy.html');
    $terms = File::get(packetPath().'/terms.html');

    expect($privacy)->toContain('Act 843')
        ->and($terms)->toContain('credits');
});

it('warns on the console when the controller is still unnamed', function (): void {
    // Not a failure. The pages are still worth sending to a lawyer; the point
    // is that the gap is stated out loud rather than discovered later.
    settings()->set('legal_entity_name', '');

    artisan('legal:review-packet', ['--path' => packetPath()])
        ->expectsOutputToContain('registered legal entity and address are still blank')
        ->assertSuccessful();
});

it('tells the reviewer on the cover which fields are still provisional', function (): void {
    settings()->set('legal_entity_name', '');
    settings()->set('legal_entity_address', '');

    artisan('legal:review-packet', ['--path' => packetPath()])->assertSuccessful();

    $cover = File::get(packetPath().'/index.html');

    expect($cover)->toContain('not final')
        ->and($cover)->toContain('legal_entity_name')
        ->and($cover)->toContain('legal_entity_address');
});

it('drops the provisional list once the settings are filled in', function (): void {
    // The cover has to be honest in both directions: naming the gaps while they
    // exist, and not leaving a stale warning on a page that is now complete.
    settings()->set('legal_entity_name', 'Kwaku Mensah Trading');
    settings()->set('legal_entity_address', '1 Independence Avenue, Accra');

    artisan('legal:review-packet', ['--path' => packetPath()])->assertSuccessful();

    expect(File::get(packetPath().'/index.html'))
        ->not->toContain('legal_entity_name');
});

it('shows the supplied controller on the cover once it is known', function (): void {
    settings()->set('legal_entity_name', 'Kwaku Mensah Trading');
    settings()->set('legal_entity_address', '1 Independence Avenue, Accra');

    artisan('legal:review-packet', ['--path' => packetPath()])->assertSuccessful();

    $cover = File::get(packetPath().'/index.html');

    expect($cover)->toContain('Kwaku Mensah Trading')
        ->and($cover)->toContain('1 Independence Avenue, Accra');
});

it('flags the trading name as undecided, because it is one', function (): void {
    // The name is a working name. A reviewer needs to know that, or they will
    // proofread around it and we will then rename and invalidate their comments.
    $cover = File::get(packetPath().'/index.html');

    expect($cover)->toContain('trading name is not final')
        ->and($cover)->toContain('Registrar General');
});

it('states plainly on the cover that this is not legal advice', function (): void {
    expect(File::get(packetPath().'/index.html'))
        ->toContain('not legal advice');
});

it('leaks no unrendered template code into any file', function (): void {
    // The cover is a heredoc, and a heredoc renders a bare `Carbon::now()` as
    // literal text rather than calling it -- so an expression written in place
    // instead of interpolated ships as source code in a document that is about
    // to be sent to a lawyer. The first version did exactly that to the
    // generation date, and every other test still passed, because the rest of
    // the cover happened to interpolate correctly. This checks all four files
    // for anything PHP-shaped, not just the line that was known to be broken.
    foreach (['index', 'privacy', 'terms', 'cookies'] as $slug) {
        $html = File::get(packetPath()."/{$slug}.html");

        expect($html)->not->toMatch('/[A-Za-z_\\\\]+::[a-zA-Z_]+\(/')
            ->and($html)->not->toMatch('/\{\$[a-zA-Z_]+\}/')
            ->and($html)->not->toMatch('/(?<![a-z])\$[a-z]+->/i');
    }
});

it('shows a real date on the cover rather than an expression', function (): void {
    $cover = File::get(packetPath().'/index.html');

    // A date has to look like a date. If the interpolation ever breaks again
    // this fails on the absence of the month name rather than on the presence of
    // the bug, which is the assertion that reads well when it breaks.
    expect($cover)->toMatch('/generated \d{1,2} \w+ \d{4}/');
});

it('fails loudly rather than writing an empty file when a page will not render', function (): void {
    // A legal page that 500s must not come out as a file that looks like a real
    // page. The command fails and the partial packet is not dressed up as a
    // complete one.
    $this->partialMock(Kernel::class)
        ->shouldReceive('handle')
        ->andReturn(new Response('nope', 500));

    $path = packetPath().'-broken';

    artisan('legal:review-packet', ['--path' => $path])
        ->expectsOutputToContain('did not render')
        ->assertFailed();

    expect(File::exists($path.'/privacy.html'))->toBeFalse();
});

function packetPath(): string
{
    // A fixed path, cleaned once in beforeEach, so the command can be re-run
    // over the top of an existing packet and the assertions read whatever the
    // most recent run produced.
    $path = storage_path('app/testing-legal-packet');

    File::ensureDirectoryExists($path);

    return $path;
}
