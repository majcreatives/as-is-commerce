<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Legal\OperatorIdentity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Renders the legal pages to standalone HTML that can be read without a server.
 *
 * WHY THIS EXISTS. The privacy notice, the terms and the cookie notice have all
 * three been written, and the only remaining blocker on them is a lawyer. The
 * pages exist as Blade templates served by an application that is not deployed
 * anywhere a lawyer can reach -- production is unapproved, staging has not been
 * cut for this, and the domain does not exist yet. So the review could not
 * start, and every day it waits is a day on the critical path for launch that
 * nobody chose to spend.
 *
 * A lawyer cannot review a Blade template. They can review a document. This
 * turns one into the other: three self-contained HTML files, styled as the site
 * is styled, openable in any browser and printable to PDF, with no Blade, no
 * build step, no server and no credentials involved.
 *
 * IT RENDERS THROUGH THE REAL HTTP KERNEL rather than compiling the views
 * directly, so what comes out is what a visitor would actually receive, not an
 * approximation of it. That matters for the review to be worth anything: the
 * point of handing over these pages is that they are the text customers are
 * shown, and a hand-assembled approximation would be a different promise.
 *
 * EVERY PAGE IS MARKED AS A DRAFT, on its face. An unmarked draft is the one
 * thing worse than no draft: it can be mistaken for the final version, and
 * nothing here is final until counsel says it is.
 */
class BuildLegalReviewPacket extends Command
{
    protected $signature = 'legal:review-packet
                            {--path= : Where to write the packet. Defaults to storage/app/legal-review}';

    protected $description = 'Render the legal pages to standalone HTML for review. Writes nothing but files.';

    /** @var list<array{slug: string, route: string, title: string}> */
    private const PAGES = [
        ['slug' => 'privacy', 'route' => 'privacy', 'title' => 'Privacy notice'],
        ['slug' => 'terms', 'route' => 'terms', 'title' => 'Terms of use'],
        ['slug' => 'cookies', 'route' => 'cookies', 'title' => 'Cookie notice'],
    ];

    public function handle(HttpKernel $http): int
    {
        $directory = $this->option('path')
            ?: storage_path('app/legal-review/'.$this->stamp());

        File::ensureDirectoryExists($directory);

        $css = $this->stylesheet();
        $identity = OperatorIdentity::fromSettings();
        $written = [];

        foreach (self::PAGES as $page) {
            $html = $this->render($http, $page['route']);

            if ($html === null) {
                $this->error($page['title'].' did not render. Nothing written for it.');

                return self::FAILURE;
            }

            $file = $directory.'/'.$page['slug'].'.html';
            File::put($file, $this->present($html, $css, $page['title']));

            $written[] = $page['slug'].'.html';
        }

        $cover = $directory.'/index.html';
        File::put($cover, $this->cover($written, $identity, $directory));

        $this->line('Wrote '.(count($written) + 1).' file(s) to:');
        $this->line('  '.$directory);
        $this->line('');
        $this->line('Open index.html. Every page is self-contained: no server, no network, no credentials.');
        $this->line('');

        if (! $identity->isComplete()) {
            $this->warn('NOTE: the registered legal entity and address are still blank, so the pages show a');
            $this->warn('      draft marker where a controller has to be named. They are settings now --');
            $this->warn('      fill them in on /admin/settings and re-run this command.');
        }

        return self::SUCCESS;
    }

    /**
     * Render one public route through the real kernel, middleware and all.
     *
     * Null rather than an empty string on a non-200, because a legal page that
     * 500s must not be written out as a file that looks like a real page.
     */
    private function render(HttpKernel $http, string $route): ?string
    {
        $response = $http->handle(Request::create('/'.$route, 'GET'));

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return (string) $response->getContent();
    }

    /**
     * The compiled stylesheet, read from the build manifest.
     *
     * Read rather than re-authored, so the packet looks like the site instead of
     * like an approximation of the site. An empty string when there is no build
     * is survivable -- counsel can still read unstyled HTML -- so that is
     * reported and not treated as fatal.
     */
    private function stylesheet(): string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->warn('No build manifest; the packet will be unstyled. Run `npm run build` to fix that.');

            return '';
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $file = $entries['resources/css/app.css']['file'] ?? null;

        if (! is_string($file) || ! is_file(public_path('build/'.$file))) {
            return '';
        }

        return (string) file_get_contents(public_path('build/'.$file));
    }

    /**
     * Inline the CSS, drop the scripts, and stamp the page as a draft.
     *
     * The scripts go because a legal document that executes JavaScript is a worse
     * thing to hand a lawyer than one that does not: it behaves differently
     * depending on where it is opened, and there is nothing on these pages for a
     * script to do.
     */
    private function present(string $html, string $css, string $title): string
    {
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);

        // Every <link> into the build directory goes: the stylesheet is inlined
        // below, and the preload and modulepreload hints point at files that do
        // not exist once this has left the machine. Matching on the href rather
        // than on rel="stylesheet" is deliberate -- what Vite actually emits here
        // is a rel="preload" as="style" hint, so a rule that only knew about
        // stylesheet links would have left it behind, pointing at nothing.
        $html = (string) preg_replace('#<link\b[^>]*href=["\'][^"\']*/build/[^"\']*["\'][^>]*>#i', '', $html);

        $style = $css === '' ? '' : '<style>'.$css.'</style>';

        // Inlined after </head> if it is there, and before <body> otherwise, so
        // the styles apply without depending on the exact shape of the layout.
        if (str_contains($html, '</head>')) {
            $html = str_replace('</head>', $style.'</head>', $html);
        } else {
            $html = $style.$html;
        }

        return str_replace('<body', '<body'.$this->banner($title), $this->relink($html));
    }

    /**
     * Point the three legal links at the sibling files in the packet.
     *
     * The rendered pages carry absolute URLs to wherever APP_URL pointed when
     * they were rendered, which offline is a link to nothing. A reviewer who
     * follows "Privacy notice" from inside the terms should land on the privacy
     * notice that is sitting in the same folder, and should not have to go back
     * to index.html to find it.
     *
     * The remaining navigation links are left exactly as rendered. They point at
     * pages that are not part of this packet and genuinely do not exist in it,
     * which is said plainly on the cover rather than papered over by rewriting
     * them into something that looks present but is not.
     */
    private function relink(string $html): string
    {
        foreach (['privacy', 'terms', 'cookies'] as $slug) {
            $html = (string) preg_replace(
                '#(href=["\'])(https?://[^"\']*?)?/?'.preg_quote($slug, '#').'/?(["\'/])#i',
                '${1}'.$slug.'.html${3}',
                $html
            );
        }

        return $html;
    }

    private function banner(string $title): string
    {
        $generated = Carbon::now()->format('j F Y');

        return ' style="background:#fff7ed;border-bottom:2px solid #c2410c;padding:14px 20px;'
            .'font-family:ui-sans-serif,system-ui,sans-serif;font-size:13px;line-height:1.5;color:#7c2d12"'
            .' role="note"'
            .'><strong>DRAFT — NOT LEGAL ADVICE, NOT FOR PUBLICATION.</strong> '
            .'This is the '.strtolower($title).' as a customer would currently receive it, rendered '
            .'on '.$generated.'. It is unreviewed. Please read it as a draft and mark it up freely; '
            .'nothing here has been signed off, and the operator\'s registered legal entity and address '
            .'are not yet filled in, so those read as a visible placeholder.</p> ';
    }

    /**
     * The cover, which is the part that makes the packet actionable.
     *
     * A folder of three HTML files and no explanation gets skimmed. What a
     * reviewer needs first is what is provisional, what is factually load-bearing
     * and what they are actually being asked to do.
     *
     * @param  list<string>  $written  Filenames of the pages that were rendered.
     */
    private function cover(array $written, OperatorIdentity $identity, string $directory): string
    {
        $name = (string) config('app.name');
        $generated = Carbon::now()->format('j F Y');
        $entity = $identity->name() ?? '[NOT YET SUPPLIED]';
        $address = $identity->address() ?? '[NOT YET SUPPLIED]';
        $dpc = $identity->dpcRegistration();

        $missing = $identity->missing();

        $rows = '';

        foreach ($written as $file) {
            $rows .= '<tr><td style="padding:6px 10px"><a href="'.$file.'" style="color:#0f172a">'.$file.'</a></td>'
                .'<td style="padding:6px 10px">Open in a browser, or print to PDF.</td></tr>';
        }

        $provisional = $missing === []
            ? '<li>Nothing on the pages is a placeholder any more.</li>'
            : '<li>These settings are still blank, and the pages print a visible marker where they belong: <code>'
                .implode('</code>, <code>', $missing).'</code>. They are filled in on the admin settings screen, not in code.</li>';

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <title>Legal pages for review — {$name}</title>
        <style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;line-height:1.6;color:#0f172a;
        max-width:52rem;margin:0 auto;padding:2.5rem 1.5rem;background:#f8fafc}
        h1{font-size:1.6rem;margin:0 0 .25rem}h2{font-size:1.1rem;margin:2rem 0 .5rem}
        code{background:#e2e8f0;padding:1px 5px;border-radius:3px;font-size:.85em}
        table{border-collapse:collapse;width:100%;margin:.5rem 0}
        td,th{border:1px solid #cbd5e1;padding:6px 10px;text-align:left;font-size:.9rem}
        th{background:#e2e8f0}.warn{background:#fff7ed;border-left:4px solid #c2410c;padding:.9rem 1.1rem;margin:1.5rem 0}
        ul{padding-left:1.2rem}li{margin:.3rem 0}
        </style>
        </head>
        <body>
        <h1>Legal pages for review</h1>
        <p style="color:#475569">{$name} &middot; generated {$generated} &middot; not deployed anywhere</p>

        <div class="warn">
        <strong>These are drafts and not legal advice.</strong> They were written from what the
        software actually does &mdash; every claim about what is collected, who receives it and how
        long it is kept was checked against the schema and the payment gateway &mdash; but the
        wording, the limitation of liability and the enforceability are a lawyer's to settle.
        </div>

        <h2>What is being asked</h2>
        <ul>
        <li>Read all three and tell us what is wrong, misleading, unenforceable or missing.</li>
        <li>Mark them up however you like &mdash; the files are plain HTML, so track changes and comments both work.</li>
        <li>The factual content is ours and was verified. If you change a <em>fact</em> rather than the
        wording, please say so explicitly, because the code has to change with it and the page has to
        stay true to the software.</li>
        </ul>

        <h2>The pages</h2>
        <table>
        <tr><th>File</th><th>Note</th></tr>
        {$rows}
        </table>
        <p style="font-size:.9rem;color:#475569">Each is a complete, styled page, and you can move
        between the three from the links inside them. Only these three pages are in the packet &mdash;
        the other links in the site navigation are left pointing at the real site and are not included here.</p>
        <p style="font-size:.9rem;color:#475569">Start with <a href="index.html#facts">the facts below</a> &mdash;
        they are the parts most likely to be argued about.</p>

        <h2 id="facts">Facts the wording depends on</h2>
        <table>
        <tr><th>Thing</th><th>Value</th></tr>
        <tr><td>Trading name</td><td>{$name}</td></tr>
        <tr><td>Registered legal entity</td><td>{$entity}</td></tr>
        <tr><td>Registered postal address</td><td>{$address}</td></tr>
        <tr><td>Data Protection Commission registration</td><td>'.($dpc ?? 'none yet &mdash; shown as blank on the notice').'</td></tr>
        <tr><td>Governing law</td><td>Laws of the Republic of Ghana</td></tr>
        <tr><td>Lawful bases relied on</td><td>Contract, legitimate interests, consent &mdash; and nothing else</td></tr>
        <tr><td>Payments</td><td>Paystack. Card details never reach our servers</td></tr>
        <tr><td>SMS</td><td>Arkesel, not currently switched on</td></tr>
        </table>

        <div class="warn">
        <strong>The trading name is not final.</strong> "{$name}" is a working name. It appears in the
        privacy notice and the terms as the name the business trades as, and a trading name used in
        commerce in Ghana is normally registered with the Registrar General's Department &mdash; so please
        review the wording around it, but treat the name itself as a blank.
        </div>

        <h2>What is still provisional</h2>
        <ul>
        {$provisional}
        <li>The operator's postal address is the address a complaint can be served at, not a PO box.</li>
        <li>There is no self-service account deletion, and the privacy notice says so plainly rather
        than promising one. Please check that is an acceptable answer under Act 843, or tell us what
        you would need in order to offer one.</li>
        <li>Account closure is done by hand, on request, by email.</li>
        </ul>

        <h2>One thing to look at first</h2>
        <p>The credit-not-money distinction is the single most important clause in the terms and the one
        most likely to be argued about, so it is stated plainly rather than softened: credits have no
        universal cash value, bidding credits are consumed permanently and are not refunded, and the
        Store Wallet is a separate cash balance that is not a credit refund. If that reads as unfair to a
        customer, that is worth raising now rather than after launch.</p>

        <h2>Regenerating</h2>
        <p style="font-size:.9rem;color:#475569">Written to <code>{$directory}</code> by
        <code>php artisan legal:review-packet</code>. Re-run it after any change to the pages or the
        settings; it renders whatever a customer would receive at that moment.</p>
        </body>
        </html>
        HTML;
    }

    private function stamp(): string
    {
        return Carbon::now()->format('Y-m-d-His');
    }
}
