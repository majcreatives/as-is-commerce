{{-- The frame every administration screen lives in.

     Two columns on a desktop: the navigation on the left, sticky so the links
     stay reachable while an operator works their way down a long screen, and
     the screen's own content on the right. On a phone the grid collapses to a
     single column and the navigation becomes a horizontally scrollable strip
     above the content, where a real side rail would run off the screen.

     This is layout only. Who sees which link is decided inside
     components/admin/nav.blade.php, from the operator's own permissions. --}}

<div class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-10">
    <x-admin.nav />

    <div class="min-w-0">
        {{ $slot }}
    </div>
</div>