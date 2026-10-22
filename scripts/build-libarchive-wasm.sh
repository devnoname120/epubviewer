#!/bin/sh

# SPDX-FileCopyrightText: 2026 Paul
# SPDX-License-Identifier: AGPL-3.0-or-later

set -eu

version='3.8.10'
source_sha256='56cad765459d5ee25a3b761f90c47d99379d28297fbfb72d908880d6b875fb96'
module_sha256='67ec2dc301a277bd53fd1063e08a12e6c6effa6eb5428a6f06a458dfca0783bd'
wasm_sha256='4f7612505168660da97d936a0cf8ff506ba8bbc47ecb95843af86d7a9bd878dc'
image='emscripten/emsdk@sha256:5aea37132ad0827908065a5d7432d2f4048011dce7169aa1ab3441b0720312e2'

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
vendor="$root/src/vendor/libarchive"
temporary=$(mktemp -d "${TMPDIR:-/tmp}/epubviewer-libarchive-wasm.XXXXXX")
trap 'rm -rf "$temporary"' EXIT HUP INT TERM

[ -f "$vendor/comic_archive.c" ] || { printf '%s\n' 'Missing comic_archive.c.' >&2; exit 1; }
[ -f "$vendor/exported-functions.json" ] || { printf '%s\n' 'Missing exported-functions.json.' >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { printf '%s\n' 'curl is unavailable.' >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { printf '%s\n' 'Docker is unavailable.' >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { printf '%s\n' 'Python 3 is unavailable.' >&2; exit 1; }
command -v shasum >/dev/null 2>&1 || { printf '%s\n' 'shasum is unavailable.' >&2; exit 1; }

archive="$temporary/libarchive-$version.tar.xz"
curl -q --fail --location --silent --show-error \
	"https://github.com/libarchive/libarchive/releases/download/v$version/libarchive-$version.tar.xz" \
	--output "$archive"
printf '%s  %s\n' "$source_sha256" "$archive" | shasum -a 256 -c -
tar -xJf "$archive" -C "$temporary"

cp "$vendor/comic_archive.c" "$temporary/comic_archive.c"
cp "$vendor/exported-functions.json" "$temporary/exported-functions.json"
cat > "$temporary/build.sh" <<'BUILD'
#!/bin/sh
set -eu
cd /source
emconfigure ./configure \
	--disable-shared \
	--enable-static \
	--disable-bsdtar \
	--disable-bsdcat \
	--disable-bsdcpio \
	--disable-posix-regex-lib \
	--disable-xattr \
	--disable-acl \
	--without-zlib \
	--without-bz2lib \
	--without-libb2 \
	--without-iconv \
	--without-lz4 \
	--without-zstd \
	--without-lzma \
	--without-cng \
	--without-openssl \
	--without-md \
	--without-xml2 \
	--without-xmllite \
	--without-bsdxml \
	--without-expat >/output/configure.log
emmake make -j8 libarchive.la >/output/make.log
emcc /output/comic_archive.c .libs/libarchive.a \
	-I ./libarchive \
	-o /output/comic_archive.mjs \
	-O3 \
	-s MODULARIZE=1 \
	-s EXPORT_ES6=1 \
	-s EXPORT_NAME=createComicArchive \
	-s ENVIRONMENT=web,worker \
	-s WASM_BIGINT=0 \
	-s ALLOW_MEMORY_GROWTH=1 \
	-s MAXIMUM_MEMORY=268435456 \
	-s FILESYSTEM=0 \
	-s MALLOC=emmalloc \
	-s EXPORTED_RUNTIME_METHODS='["UTF8ToString"]' \
	-s EXPORTED_FUNCTIONS=@/output/exported-functions.json \
	-s ERROR_ON_UNDEFINED_SYMBOLS=0
BUILD
chmod +x "$temporary/build.sh"

docker run --rm --platform linux/amd64 \
	--mount "type=bind,src=$temporary/libarchive-$version,dst=/source" \
	--mount "type=bind,src=$temporary,dst=/output" \
	"$image" /output/build.sh

[ -s "$temporary/comic_archive.mjs" ] || { printf '%s\n' 'The generated JavaScript module is empty.' >&2; exit 1; }
[ -s "$temporary/comic_archive.wasm" ] || { printf '%s\n' 'The generated WebAssembly module is empty.' >&2; exit 1; }
python3 - "$temporary/comic_archive.mjs" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text(encoding='utf-8')
path.write_text('\n'.join(line.rstrip() for line in text.splitlines()) + '\n', encoding='utf-8')
PY
printf '%s  %s\n' "$module_sha256" "$temporary/comic_archive.mjs" | shasum -a 256 -c -
printf '%s  %s\n' "$wasm_sha256" "$temporary/comic_archive.wasm" | shasum -a 256 -c -
install -m 0644 "$temporary/comic_archive.mjs" "$vendor/comic_archive.mjs"
install -m 0644 "$temporary/comic_archive.wasm" "$vendor/comic_archive.wasm"

shasum -a 256 "$vendor/comic_archive.mjs" "$vendor/comic_archive.wasm"
