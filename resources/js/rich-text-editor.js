/*
 * The blog body / product description editor.
 *
 * TipTap (ProseMirror), loaded lazily so the kilobytes only arrive on a page
 * that actually mounts an editor. The toolbar is ordinary buttons: formatting
 * only, no inline styles, no images -- which is what the server-side HTML
 * purifier will accept, and what the strict Content-Security-Policy will
 * render. Where there is no JS at all the field is not on the page, because
 * the server renders the plain textarea, not this.
 *
 * The editor speaks to Livewire through `$wire.set(property, html, false)`:
 * typing writes the HTML into the component's property without a request per
 * keystroke (the save button flushes the deferred value), and when the
 * component re-renders with a different value -- an edit() that refills the
 * form, a fresh record -- `$wire.$watch` flows it back in here and the
 * document is replaced. `wire:ignore` keeps Livewire from morphing the toolbar
 * and ProseMirror surface while that happens.
 *
 * NO `$wire.entangle()`. Livewire v4 discourages entangle for two-way edited
 * rich content: it keeps a second source of truth on the Alpine side that does
 * not reliably reach the wire model, so a save can flush an empty property
 * even though the editor visibly shows text. A plain deferred `$wire.set`
 * writes the HTML straight into the component's reactive state, and the watch
 * below handles server -> editor refill instead of entanglement.
 */

/**
 * @typedef {object} RichTextOptions
 * @property {string} property The Livewire property to bind (e.g. "body").
 * @property {string|null} [initialHtml] Content rendered by the server on first mount.
 */

/**
 * @param {RichTextOptions} options
 */
export function richTextEditor(options = {}) {
    return {
        property: options.property ?? null,
        editor: null,
        ready: false,
        focused: false,
        /** Bumped on every transaction so the toolbar active states re-evaluate. */
        refresh: 0,
        /** The editor's HTML on this side of the wire. */
        body: '',

        init() {
            if (! this.property) {
                return;
            }

            // Read the server value from the wire model rather than entangling
            // a second copy of it. The server-rendered @js($value) is the
            // fallback for the moment before Livewire has hydrated.
            this.body = this.$wire.get(this.property) ?? options.initialHtml ?? '';

            // Server -> editor: refill the document when the component's value
            // changes behind the editor's back (edit(), save(), validation
            // re-render). Guarded so a write that came from the editor itself
            // does not re-set the document and jump the cursor.
            this.$wire.$watch(this.property, (value) => {
                if (this.editor && this.editor.getHTML() !== (value ?? '')) {
                    this.editor.commands.setContent(value ?? '', false);
                }
            });

            this.mountEditor();
        },

        async mountEditor() {
            const [{ Editor }, { default: StarterKit }, { default: Underline }, { default: LinkExtension }] =
                await Promise.all([
                    import('@tiptap/core'),
                    import('@tiptap/starter-kit'),
                    import('@tiptap/extension-underline'),
                    import('@tiptap/extension-link'),
                ]);

            this.editor = new Editor({
                element: this.$refs.editor,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [2, 3] },
                    }),
                    Underline,
                    LinkExtension.configure({
                        openOnClick: false,
                        autolink: true,
                        defaultProtocol: 'https',
                        HTMLAttributes: {
                            target: '_blank',
                            rel: 'noopener noreferrer nofollow',
                        },
                    }),
                ],
                content: this.body || '',
                onUpdate: () => this.editorWasUpdated(),
                onSelectionUpdate: () => this.refresh++,
                onTransaction: () => this.refresh++,
                onFocus: () => this.focused = true,
                onBlur: () => this.focused = false,
            });

            this.ready = true;
        },

        editorWasUpdated() {
            if (this.editor) {
                // Deferred ($wire.set's third argument is "live"; false queues
                // the value locally instead of firing a request per keystroke)
                // so the save button flushes it with the rest of the form.
                this.$wire.set(this.property, this.editor.getHTML(), false);
            }
        },

        /** Run a chainable command: `exec('toggleBold')`, `exec('toggleHeading', { level: 2 })`. */
        exec(command, ...args) {
            this.editor?.chain().focus()[command](...args).run();
        },

        /** Toggle the link on the current selection, via the browser URL prompt. */
        setLink() {
            const previous = this.editor?.getAttributes('link').href ?? null;
            const url = window.prompt('Link URL', previous ?? 'https://');

            if (url === null) {
                return;
            }

            const trimmed = url.trim();

            if (trimmed === '') {
                this.exec('unsetLink');
                return;
            }

            this.exec('setLink', { href: trimmed });
        },

        isActive(name, attributes) {
            if (! name) {
                return false;
            }

            return Boolean(this.editor?.isActive(name, attributes));
        },

        toolbarClass(name, attributes) {
            // Reading `refresh` here is what makes Alpine re-evaluate the class
            // after each transaction.
            void this.refresh;

            if (! name) {
                return 'text-slate-600 hover:bg-slate-100 hover:text-slate-900';
            }

            return this.isActive(name, attributes)
                ? 'bg-brand-100 text-brand-800 ring-brand-300'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900';
        },
    };
}