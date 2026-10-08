@props([
    'label',
    'name',
    'state' => $name ?? 'body',
    'value' => '',
    'error' => null,
    'optional' => false,
    'hint' => null,
])

<x-field :label="$label" :name="$name" :error="$error" :optional="$optional" :hint="$hint">
    {{-- A rich-text control bound to a Livewire property. The toolbar is plain
         formatting: bold, italic, lists, headings, links -- nothing that carries
         an inline style or a width, because the server-side HTML purifier allows
         exactly what is offered here and nothing else.

         The whole surface is wrapped in wire:ignore so Livewire never morphs the
         toolbar or the ProseMirror surface while the editor holds the user's
         cursor. The value travels both ways through $wire.entangle().deferrable
         inside the Alpine component: typing is written into the Livewire
         property without a request per keystroke (Save flushes it), and a
         component re-render that changes the property -- edit() refilling the
         form, a fresh record -- flows back in and replaces the document.

         There is no JS without Alpine, and there is no fallback textarea here by
         design: where the bundle cannot load there is simply no editor on the
         page (the form validation still requires the property, so nothing can be
         half-saved). --}}
    <div
        x-data="richTextEditor({ property: @js($state), initialHtml: @js($value) })"
        wire:ignore
        @class([
            'rich-text-editor block w-full overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-inset transition',
            'ring-slate-300 focus-within:ring-2 focus-within:ring-brand-600' => ! $error,
            'ring-2 ring-red-400' => $error,
        ])>
        <div class="flex flex-wrap items-center gap-0.5 border-b border-slate-200 bg-slate-50 px-2 py-1.5"
             role="toolbar" aria-label="{{ $label }} formatting">
            <x-editor-button label="Bold" title="Bold" :toggle="'bold'"
                             @click="exec('toggleBold')" />
            <x-editor-button label="Italic" title="Italic" :toggle="'italic'"
                             @click="exec('toggleItalic')" />
            <x-editor-button label="Underline" title="Underline" :toggle="'underline'"
                             @click="exec('toggleUnderline')" />
            <x-editor-button label="Strike" title="Strikethrough" :toggle="'strike'"
                             @click="exec('toggleStrike')" />
            <x-editor-button label="H2" title="Heading 2" :toggle="'heading'" :toggle-attributes="['level' => 2]"
                             @click="exec('toggleHeading', { level: 2 })" />
            <x-editor-button label="H3" title="Heading 3" :toggle="'heading'" :toggle-attributes="['level' => 3]"
                             @click="exec('toggleHeading', { level: 3 })" />
            <x-editor-button label="List" title="Bulleted list" :toggle="'bulletList'"
                             @click="exec('toggleBulletList')" />
            <x-editor-button label="Numbered" title="Numbered list" :toggle="'orderedList'"
                             @click="exec('toggleOrderedList')" />
            <x-editor-button label="Quote" title="Block quote" :toggle="'blockquote'"
                             @click="exec('toggleBlockquote')" />
            <x-editor-button label="Code" title="Inline code" :toggle="'code'"
                             @click="exec('toggleCode')" />
            <x-editor-button label="Code block" title="Code block" :toggle="'codeBlock'"
                             @click="exec('toggleCodeBlock')" />
            <x-editor-button label="Link" title="Add a link" :toggle="'link'"
                             @click="setLink()" />
            <x-editor-button label="Rule" title="Horizontal rule"
                             @click="exec('setHorizontalRule')" />
            <x-editor-button label="Undo" title="Undo" @click="exec('undo')" />
            <x-editor-button label="Redo" title="Redo" @click="exec('redo')" />
        </div>

        <div x-ref="editor"
             class="prose prose-slate max-w-none min-h-[16rem] px-3 py-2.5 text-slate-900 sm:text-sm"
             data-placeholder="Write the {{ strtolower($label) }} here..."></div>
    </div>
</x-field>