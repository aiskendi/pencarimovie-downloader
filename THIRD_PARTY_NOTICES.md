# Third-Party Notices

PencariMovie Downloader bundles or can be packaged with third-party software. Each third-party component remains governed by its own license terms.

## Runtime components

- FrankenPHP / Caddy runtime binaries may be bundled in `bin/` for release packages. See the license files included with the FrankenPHP distribution and the files copied into `bin/`, including `bin/license.txt`, `bin/README.md`, and `bin/readme-redist-bins.txt` when present.
- PHP runtime binaries and DLLs may be bundled in `bin/` for Windows packages. See the PHP license files included in the bundled runtime directory.
- OpenSSL, curl, zlib, libzip, and other native libraries may be bundled transitively with the runtime. Their notices are included in the runtime distribution files where available.

## PHP dependencies

- Composer dependencies are declared in `composer.json` and locked in `composer.lock`.
- Release packages may include `vendor/` so end users do not need Composer.
- Each package under `vendor/` keeps its own `LICENSE`, `COPYING`, `NOTICE`, or equivalent file when provided by the upstream project.
- MadelineProto and its dependencies are installed through Composer and remain under their upstream licenses.

## User responsibility

Users and redistributors should review bundled third-party license files before redistributing modified release packages.
