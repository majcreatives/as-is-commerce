<x-layouts.app title="Profile">
    <x-page-header
        title="Profile"
        description="Manage your account details, password and notifications." />

    <div class="grid gap-4 lg:grid-cols-2">
        <livewire:profile.update-profile-information />
        <livewire:profile.update-password />
    </div>

    <div class="mt-4">
        <livewire:profile.verify-email-form />
    </div>

    <div class="mt-4">
        <livewire:profile.notification-preferences-form />
    </div>
</x-layouts.app>
