## 1.9.1 - 2026-02-18

### Added

- Automated Nextcloud App Store publishing via GitHub Actions

### Fixed

- Removed unused `sabre/dav` and `sabre/xml` to fix spammy download warnings (see [GitHub discussion #40](https://github.com/devnoname120/epubviewer/discussions/40) and [Nextcloud forum report](https://help.nextcloud.com/t/php-warning-during-the-download-of-multiple-files/236252))

### Changed

- Removed unused dependencies: `symfony/event-dispatcher`, `@nextcloud/auth`, `@nextcloud/axios`, `@nextcloud/event-bus`, `@nextcloud/files`, `@nextcloud/l10n`, `@nextcloud/paths`, `@nextcloud/sharing`, `@nextcloud/vue`

## 1.9.0 - 2026-02-18

### Changed

- Register a proper [`@nextcloud/viewer`](https://github.com/nextcloud/viewer) handler instead of doing a dirty DOM injection

## 1.8.1 - 2025-10-05

### Added

- (#34) `lastModified` on preferences to improve performance of [nextcloud\_books](https://git.crystalyx.net/Xefir/nextcloud_books)

### Changed

- Dropped support for Nextcloud 30
- Added support for Nextcloud 32

## 1.8.0 - 2025-06-16

### Added

- (#33) Added EPUB cover preview generation. Huge thank you to @JochemKuijpers for the implementation!

### Fixed

- Don't attempt to save the cursor and preferences when a file is previewed by an anonymous user

### Changed

- Added back support for Nextcloud 30
- Removed the use of deprecated APIs and switched to modern alternatives

## 1.7.3 - 2025-02-26

### Fixed

- Fixed PHP compatibility issues with PSR Log package on Nextcloud 31

### Changed

- Dropped support for Nextcloud 30

## 1.7.2 - 2025-02-26

### Fixed

- Temporary workaround for dependency injection breaking public share viewer when user is not connected

## 1.7.1 - 2025-02-26

### Changed

- Made database code more robust
- Improved the type checking for Event Listeners

### Fixed

- Fixed remaining Psalm errors when using a baseline
- Use `COMPOSER_NO_DEV=0` instead of `--dev`, and `COMPOSER_NO_DEV=1` for production. The reason is that Composer inexplicably decided to deprecate the `--dev` parameter in favor of this cryptic env variable (!?)

## 1.7.0 - 2025-02-24

### Changed

- Huge refactoring that fixed all (100+) Psalm linter errors.
- Added support for Nextcloud 31.
- Minimum PHP version updated to 8.1 (required by Nextcloud 31).
- Dropped support for Nextcloud 29.

## 1.6.8 - 2025-02-08

### Fixed

- (#27) Fix decompression error in some browsers due to a too strict `Content-Security-Policy`. Thanks to @lesensei for the fix!

## 1.6.7 - 2024-11-25

### Fixed

- Fix opening the viewer in public shares for anonymous (not logged-in) users.

## 1.6.6 - 2024-11-25

### Fixed

- (#26) Fix error `Class "OCA\Epubviewer\Controller\._PageController" does not exist` in `Router.php` due to packaging from macOS (https://superuser.com/a/260264).

## 1.6.5 - 2024-11-25

### Fixed

- Crash due to fragile Nextcloud controllers reflection.

## 1.6.4 - 2024-11-25

### Fixed

- (#22) Error when viewing EPUB files from public shares.

### Changed

- Nextcloud 27 support removed (EOL).

## 1.6.3 - 2024-06-22

### Fixed

- Nextcloud 27 compatibility by reverting the fix from 1.6.2 when Nextcloud 27 is running. Thanks to @smarinier for the fix!

## 1.6.2 - 2024-04-08

### Fixed

- EPUB/CBR/PDF: fix loading when the path of a file contains special characters such as `#`.

## 1.6.1 - 2024-03-30

### Fixed

- (#10) Don't load inexistent CSS file.

## 1.6.0 - 2024-03-03

### Added

- (#8) Nextcloud 28: added support

### Fixed

- (#5) CBR: fix public share loading (limitation: it only works for individually shared files, not for shared folders)

### Changed

- Big codebase refactoring (structure, JS bundling, TypeScript, linting, etc.)

## 1.5.3 - 2023-09-18

### Fixed

- (#4) EPUB: fixed fullscreen mode. Nothing was happening when clicking on the fullscreen button within the viewer.
- EPUB: fixed annotation tooltips not showing due to a bug in the library `epub.js`.

## 1.5.2 - 2023-09-18

### Fixed

- (#3) NextCloud: a [cache bug in NextCloud's autoloader](https://github.com/nextcloud/server/issues/38797) broke the app on upgrade. We now use a custom Composer autoloader in order to work around this and future bugs that NextCloud will introduce in their autoloader.

## 1.5.1 - 2023-09-15

### Fixed

- Settings: the default file association settings were not properly displayed, misleading the user into thinking that by default the app was not associated with any file type. The settings now display the correct defaults.

## 1.5.0 - 2023-08-17

### Added

- EPUB/CBZ: viewer is now inlined when opening a shared EPUB. This matches the behavior of the official PDF viewer: https://github.com/nextcloud/files\_pdfviewer

### Changed

- PDF: disabled PDF file association by default in the app settings. Users are advised to use the official PDF viewer instead: https://github.com/nextcloud/files\_pdfviewer

### Fixed

- various bugs that made it incompatible with NextCloud ≥ 22. It now works on NextCloud 26 and 27.

## 1.2.3 - 2018-02-24

### Fixed

- (#76) typo plus some missing code kept Reader from being used to preview shared files
- (#79) typo kept Reader from being used by default for CBx
- (#82) missing setDefault kept actual style settings from being saved

## 1.2.2 - 2018-02-02

### Fixed

- (#75) NC and OC are diverging, NC encodes everything on $settings as JSON, OC does not yet.

## 1.2.1 - 2018-01-31

### Changed

- change default settings to enabled for all supported mime types

## 1.2.0 - 2018-01-31

### Added

- PDF: (#73) new preference 'scroll to top of page on page turn'
- PDF: defaults and per-document settings are now saved and restored
- PDF: night mode (using CSS3 filters, only works in recent browsers), toggle with 'd', by clicking night mode button or clicking in empty area on button bar, adjust in settings

### Changed

- remove <base> from templates to avoid warning in console, <base> statement was ineffective anyway de to (overly restrictive) hardcoded policy in NC/OC.
- removed (or rather disabled) merging of PDF annotations into user bookmarks as it only served to mess up the bookmark list and slowed things down. This feature can be re-enabled once Reader gains a functional PDF annotation editor.

### Fixed

- PDF: (#72) $title not ['title'] in pdfreader template, hopefully the last remaining bug related to template refactoring
- PDF: browsing the thumbnail list in single-page mode did not work as intended due to datatype mismatch in page calculation routine, fixed with explicit toString()
- PDF: page 0 does not exist so don't try to go there

## 1.1.1 - 2018-01-19

### Added

- signed package for publication in Owncloud marketplace

### Changed

- updated bitjs unrar.js and rarvm.js

## 1.1.0 - 2018-01-18

### Added

- Reader now supports PDF
- PDF double page spreads are supported
- optional double-buffering for faster rendering, can be disabled for low-memory devices
- optional selectable text layer, can be disabled for low-memory devices

### Changed

- #38: moved declarations in js/ready.js one level lower to work around a bug in the Palemoon browser
- new version bitjs archive tools, fixes compatibility problems with some CBR files
- increased maximum supported version for OC and NC

## 1.0.4 - 2017-04-09

### Fixed

- #43, remove table aliases in hooks to avoid being bit by querybuilder/doctrine/MySQL incompatibility/idiosyncracy
- #39, #41 and #42, NOTE: if you're on MySQL or MariaDB you might need to enable 4-byte support if this has not been done yet, otherwise you'll get a '1071 Specified key was too long' error on install. More information on this issue - which also occurs when trying to use Emoji characters in a NC/OC installation on a MySQL or MariaDB database - can be found here: https://docs.nextcloud.com/server/11/admin_manual/maintenance/mysql_4byte_support.html

## 1.0.3 - 2017-03-29

### Fixed

- #40, detect shared file OR folder and (try to) get fileId for such when applicable

## 1.0.2 - 2017-03-25

### Fixed

- #37, use getAppManager()->isInstalled('files_opds') instead of class_exists to avoid log spam

### Changed

- new version bitjs unarchiver, increases compatibility with CBR files (at the cost of some speed)
- move function declarations in js/ready.js down one block level so browsers which do not support
  ES6 (e.g. Palemoon) can find them. Unfortunately the above new version of bitjs uses another ES6
  feature (classes) which Palemoon does not support so this change may be moot...

## 1.0.1 - 2017-03-19

### Fixed

- #35: Internal Server Error: fixed path resolution so app works when NC/OC hosted in subdirectory

## 1.0.0 - 2017-03-15

### Added

- Reader now supports CBR/CBZ ('comics') files
- Book position ('cursor') is saved on server and restored on next invocation
- Default settings (independent of fileid) and file-specific settings are saved and restored
- Bookmarks and annotations (notes) are saved and restored (bookmarks are a type of annotation).
- Full-text search implemented.
- Framework to support more file format renderers
- hooks added to remove defaults, settings and annotations/bookmarks for deleted files or users
- epubreader
  - night mode now works more reliably
  - new 'day mode', ie. user-defined colours
  - new font settings: font weight
  - column width user-configurable
  - new mode: maximize reader area, for small-screen devices
  - page turn arrows optional, hidden by default
- cbreader
  - supports CBR (rar) and CBZ (zip) archives
  - single and double page (spread) mode, auto-adjusts to screen geometry
  - optional image enhancement filters
  - seamless full screen mode (where browser allows user full control of experience, ie. not on apple)

## 0.8.3 - 2017-02-02

### Fixed

. #31: ReferenceError: cleanStartTextContent is not defined, caused by failure to declare local var in epub.js

## 0.8.3 - 2017-02-01

### Fixed

- missing $title parameter in template/reader.php caused warnings in log, fixed

## 0.8.2 - 2017-01-10

### Fixed

- Nextcloud-port broke compatibility with Owncloud due to OC not supporting CSPv3, workaround implemented

## 0.8.1 - 2017-01-09

### Added

- Modified info.xml, added screenshots

## 0.8.0 - 2017-01-09

### Added

- new version 0.2.15 of Futurepress epub.js renderer

### Changed

- New logo
- First release to be compatible with Nextcloud
