<x-layouts.app title="Profile">
    <x-page-header
        title="Profile"
        description="Manage your account details and password." />

    <div class="grid gap-4 lg:grid-cols-2">
        <livewire:profile.update-profile-information />
        <livewire:profile.update-password />
    </div>
</x-layouts.app>
