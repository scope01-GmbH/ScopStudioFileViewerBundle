# StudioUI File Viewer Bundle

Browse, edit and download server files from within the **Pimcore Studio** UI.

Pimcore's [file-explorer-bundle](https://github.com/pimcore/file-explorer-bundle) was not
migrated to Studio and is deprecated as of 2.2. This bundle provides the equivalent workflow
for Studio: a file tree on the left, a tabbed editor on the right.

## Features

- **File tree** over the project directory with lazy loading, so large trees stay responsive.
- **Tabbed editor** with syntax highlighting and line numbers, including **PHP and YAML**
  (see *Syntax highlighting* below). Language is detected from the file name.
- **Editing and saving** of text files, written atomically so a failed write cannot leave a
  half-written file behind.
- **Reload** of the whole tree, or of a single directory via right-click → *Reload folder*,
  so files created outside Studio show up without rebuilding the tree.
- **Create files and folders** from a directory's right-click menu — including the project
  root, which is shown as its own node so it has somewhere to right-click. A new file opens
  straight away for editing.
- **Rename and delete** any entry from its right-click menu. Deleting always asks first and
  takes a folder's whole subtree with it; open tabs follow a rename and close when the file
  behind them is gone. The project root itself cannot be renamed or deleted.
- **Download** of any file, streamed from the server — including the large and binary files
  the editor refuses to open.
- **Large files are never loaded.** Anything above `max_editable_size` is refused with a
  warning; the last `tail_bytes` can be shown read-only instead, which is what you usually
  want for a log file. Binary files are detected and not opened at all.
- **Admin only.** Every route requires a Pimcore admin user, independent of the project's
  `access_control` rules.

## Requirements

- PHP 8.4+
- Pimcore **2026.2** with `pimcore/studio-ui-bundle` — one platform line per release, see
  *Versioning* below

## Versioning

Releases are numbered after the Pimcore platform they target, the way the Pimcore packages
themselves are: `2026.2.0`, `2026.2.1`, and so on.

The third segment is this bundle's own release counter and has nothing to do with Pimcore's
patch level — bundle `2026.2.3` does **not** mean "needs Pimcore 2026.2.3", it means the
fourth release for the 2026.2 platform.

Require it pinned to one line:

```json
"scope01/studio-file-viewer-bundle": "~2026.2.0"
```

`~2026.2.0` is `>=2026.2.0 <2026.3.0`. Do not use `^2026.2` — with a calendar major that
means `<2027.0.0`, which would happily install a build meant for a different platform line.
The frontend ships as a prebuilt module federation bundle compiled against a pinned
`@pimcore/studio-ui-bundle`, so a release really does belong to exactly one line.

Each platform line has its own branch (`2026.2`, …) for fixes; `main` tracks the newest one.

Releases up to and including `v1.4.0` used semantic versioning. A constraint of `^1.0` will
not see the calendar releases at all — composer keeps such a project on `1.4.0` silently, so
the constraint has to be changed by hand once.

Because the version now tracks the platform rather than this bundle's own surface, a change
to the configuration schema (`scop_studio_file_viewer.*`) or to the API routes is called out
in the release notes instead of being visible in the number.

## Installation

```bash
composer require scope01/studio-file-viewer-bundle:~2026.2.0
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Scop\StudioFileViewerBundle\ScopStudioFileViewerBundle::class => ['all' => true],
];
```

Then clear the cache. The frontend build ships with the package and is unpacked
automatically at cache warmup — there is no build step for consumers.

```bash
bin/console cache:clear
```

The entry appears in the Studio main navigation under **System → File Viewer**.

## Configuration

All values are optional; the defaults are shown.

```yaml
# config/packages/scop_studio_file_viewer.yaml
scop_studio_file_viewer:
    # Directory exposed to the viewer. Nothing outside of it can be read or written.
    root_path: '%kernel.project_dir%'

    # Set to false for a read-only viewer, e.g. on production.
    writable: true

    # Files larger than this (bytes) are not loaded into the editor.
    max_editable_size: 2097152   # 2 MB

    # How much of the end of a large file the read-only tail view returns.
    tail_bytes: 262144           # 256 KB

    # Paths relative to root_path that are hidden and cannot be read or written.
    excluded_paths: ['.git']
```

### Hardening suggestions

The viewer intentionally exposes the whole project directory, which is what makes it useful
and also what makes it powerful. On sensitive installations consider:

```yaml
scop_studio_file_viewer:
    writable: false
    excluded_paths: ['.git', '.env', '.env.local', 'var/config']
```

## API

All routes sit under the Studio API prefix (`pimcore_studio_backend.url_prefix`, by default
`/pimcore-studio/api`) so they are covered by the `pimcore_studio` firewall, and all of them
additionally require an admin user.

| Method | Path                          | Purpose                                   |
|--------|-------------------------------|-------------------------------------------|
| GET    | `…/scop-file-viewer/config`    | Effective limits and root                 |
| GET    | `…/scop-file-viewer/directory` | List one directory (`?path=`)             |
| GET    | `…/scop-file-viewer/file`      | Read a file (`?path=`, `?tail=1`)         |
| PUT    | `…/scop-file-viewer/file`      | Overwrite a file (`{path, content}`)      |
| POST   | `…/scop-file-viewer/entry`     | Create a file or folder (`{path, name, type}`) |
| PATCH  | `…/scop-file-viewer/entry`     | Rename an entry in place (`{path, name}`) |
| DELETE | `…/scop-file-viewer/entry`     | Delete an entry, folders recursively (`?path=`) |
| GET    | `…/scop-file-viewer/file/download` | Stream a file as an attachment (`?path=`) |

Creating is only offered inside an existing directory, through that directory's context menu.
A rename takes a single name, never a path, so it can never move an entry somewhere else.
Everything that writes needs `writable: true`; with the viewer read-only, creating, renaming
and deleting are refused along with saving.

## Translations

The UI ships English and German; English is the base and any missing key falls back to it.

```
src/Resources/translations/studio.en.yaml
src/Resources/translations/studio.de.yaml
```

Symfony picks these up automatically from the bundle path, and Studio merges them into its
own `studio` catalogue — there is nothing to register. All keys are namespaced under
`scop-file-viewer.` and use flat dot notation, which is the only form the Studio translator
resolves.

To add a locale, copy `studio.en.yaml` to `studio.<locale>.yaml` and translate the values.
`TranslationsTest` fails the build if a locale is missing a key, carries one the base
catalogue does not have, or changes a `{{placeholder}}`, and if the UI uses a key that is not
translated (or ships one it never uses).

## Syntax highlighting

The bundle ships its **own CodeMirror** rather than reusing Studio's, which is what makes PHP
and YAML highlighting possible.

Studio's `TextEditor` only supports `html`, `css`, `javascript`, `json`, `xml`, `sql` and
`markdown`. Adding a language to it from the outside is not possible: Studio's
`mf-manifest.json` declares no shared modules, so its CodeMirror is sealed inside the Studio
remote, and CodeMirror resolves extensions by facet identity — extensions built against a
second copy are rejected at runtime.

Shipping a self-contained copy avoids that entirely. It costs ~695 KB, which is **lazily
loaded**: the chunk is only fetched when the first file is opened, so the remote Studio pulls
in at startup stays around 160 KB.

Highlighted: PHP (incl. `.phtml`), YAML, JavaScript/TypeScript/JSX, JSON (incl.
`composer.lock`), HTML/Twig, CSS/SCSS/LESS, XML/XSD/SVG, SQL and Markdown.

## Security

Reading and writing arbitrary server files is an administrator capability: it reaches `.env`,
configuration and anything else the PHP process can touch.

- Every route requires `User::isAdmin()`, checked in the bundle itself rather than delegated
  to firewall configuration.
- Every client-supplied path is resolved through a path jail: the path is normalised, then
  `realpath()`-ed, then verified to sit inside the configured root. Because the check runs on
  the resolved path, a symlink pointing outside the root is rejected too.
- Excluded paths are enforced on both the requested path and the resolved path.
- Writes are size-capped, refused for binary files, and performed atomically through a
  temporary file in the same directory that inherits the original's permissions.
- On creation the name must be a single path segment: separators, `.`, `..`, control
  characters and over-long names are rejected, so a caller cannot escape the parent
  directory it addressed. Existing entries are never overwritten, and a name already taken
  by a dangling symlink is refused too.
- Downloads go through the same path jail, refuse directories, and are served as
  `application/octet-stream` with `X-Content-Type-Options: nosniff` so a file from the
  project can never be rendered as active content on this origin.

## Development

```bash
cd assets
npm install
npm run dev          # development build
npm run dev-server   # dev server on port 3033, picked up by Studio automatically
npm run build        # production build + packs src/Resources/build-dist/build-<id>.zip
npm run check-types
```

The frontend is built with rsbuild and module federation. Studio core is consumed as a
runtime remote and is never bundled into this package's output.

### Tests

```bash
composer install
composer test
```

CI runs the suite on PHP 8.4 and 8.5, type-checks the frontend, and rebuilds it to verify the
committed archive matches the sources — consumers install the archive rather than building
it, so a stale one would ship old UI.

`PathResolver` and `FileViewerService` are deliberately framework free, so the suite needs
neither a Symfony kernel nor a Pimcore installation — it runs against a real temporary
directory, which is the only way to exercise `realpath()`, symlinks and permissions honestly.
The bulk of it covers the path jail and the read/write guards.

## License

MIT — see [LICENSE](LICENSE).

This bundle is an independent work and contains no Pimcore source code. It is a plugin for
Pimcore and Pimcore Studio, which Pimcore GmbH licenses under the Pimcore Open Core License
(POCL). The MIT license here covers this bundle's own code only and grants no rights to
Pimcore itself — you need your own valid Pimcore license to run it. See [NOTICE.md](NOTICE.md).

Not affiliated with, endorsed or supported by Pimcore GmbH.
