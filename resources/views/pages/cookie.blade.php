@php
    // Draft for review. This is the short version and it is unusually clean
    // because the audit confirmed it: the application sets no cookies of its
    // own beyond the framework session cookie, and resources/ contains no
    // third-party script, pixel, iframe, webfont or embed of any kind. There
    // are no analytics, no tag manager, no advertising cookies, no consent
    // banner and no cross-site tracking. If that ever changes -- because a
    // tracker or a widget is added -- this page becomes false and must be
    // rewritten before the change ships, and a consent mechanism will be
    // required.
@endphp

<x-layouts.app title="Cookies"
               description="This site uses one cookie, to keep you signed in and protect you from cross-site attacks. No tracking, no advertising, no analytics.">

    <x-page-header
        :title="'Cookies — '.config('app.name')"
        description="The short version: one cookie, for staying signed in. Last updated 26 September 2026." />

    <div class="space-y-6">
        <x-card title="The short version">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    This site sets <strong class="text-slate-900">one cookie</strong>, plus a
                    standard security token. There is no advertising, no analytics, no
                    tracking across other websites, and no data broker. Nothing about your
                    visit is measured, sold or shared for advertising. Because there is
                    nothing to consent to, there is no cookie banner.
                </p>
            </div>
        </x-card>

        <x-card title="What the cookie does">
            <div class="space-y-3 text-sm text-slate-600">
                <div>
                    <h3 class="font-semibold text-slate-900">Session cookie</h3>
                    <p class="mt-1">
                        Keeps you signed in and remembers what you were doing, such as which
                        basket you had open. It is required for the site to work at all, so it
                        cannot be switched off. It is marked <em>HttpOnly</em>, so scripts
                        cannot read it, and <em>SameSite</em>, so other sites cannot send it
                        here. It is removed when your session ends, and it is not a permanent
                        tracking record of you.
                    </p>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-900">Security token</h3>
                    <p class="mt-1">
                        A standard token that tells the site a request came from one of its
                        own pages and not from another site imitating it. It stops other
                        websites acting as though they were you.
                    </p>
                </div>
            </div>
        </x-card>

        <x-card title="What we do not use">
            <div class="space-y-3 text-sm text-slate-600">
                <ul class="list-disc space-y-2 pl-5">
                    <li>No advertising or retargeting cookies.</li>
                    <li>No analytics, tag manager, or audience measurement.</li>
                    <li>No social media pixels or chat widgets.</li>
                    <li>No embedded maps, video players or third-party frames that would report your visit to somebody else.</li>
                    <li>No fingerprinting, and no data sold or shared with data brokers.</li>
                </ul>
                <p>
                    Card entry happens on the payment provider's own secure page, not on ours,
                    so payment details are not handled here at all.
                </p>
            </div>
        </x-card>

        <x-card title="Controlling cookies yourself">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Your browser can block or delete cookies, and most browsers can be set to
                    refuse them. Because our only cookie is needed to sign you in, blocking
                    it means you cannot stay signed in — you would simply sign in again on
                    each visit. Clearing cookies does not lose your account, your orders or
                    your credits.
                </p>
            </div>
        </x-card>

        <x-card title="If this ever changes">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    If a future change introduces tracking, we will update this page first and
                    ask for your consent before any such cookie is set. The full detail of
                    what we collect and why is in the
                    <a href="{{ route('privacy') }}" class="underline">privacy notice</a>.
                </p>
            </div>
        </x-card>
    </div>
</x-layouts.app>
