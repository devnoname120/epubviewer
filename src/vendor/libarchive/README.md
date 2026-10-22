# Bounded libarchive WebAssembly decoder

`comic_archive.mjs` and `comic_archive.wasm` are generated from libarchive
3.8.10 with Emscripten 4.0.5. The module exposes only the narrow read-only API
used to decode one preflighted RAR3 entry; archive writing, filters, the virtual
filesystem, and command-line programs are disabled.

The wrapper selects the UTF-8 character locale before opening an entry, so
RAR3 Unicode filenames can be decoded and compared with the preflighted name.

The libarchive source archive is downloaded from the upstream 3.8.10 release
and verified with SHA-256
`56cad765459d5ee25a3b761f90c47d99379d28297fbfb72d908880d6b875fb96`.
The Emscripten build image is pinned by digest in
`scripts/build-libarchive-wasm.sh`.

Rebuild the generated files with:

```sh
./scripts/build-libarchive-wasm.sh
```

libarchive's redistribution terms are in `COPYING`. The generated Emscripten
runtime's licenses are in `LICENSE-EMSCRIPTEN.txt`. The application build copies
both notices beside the emitted WebAssembly asset.
