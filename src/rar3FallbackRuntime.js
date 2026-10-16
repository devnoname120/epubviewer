import createComicArchive from './vendor/libarchive/comic_archive.mjs'
import comicArchiveWasmUrl from './vendor/libarchive/comic_archive.wasm?url'
import { createRar3LibarchiveDecoder } from './rar3LibarchiveFallback.js'

const MAXIMUM_WASM_BYTES = 1024 * 1024

const decoder = createRar3LibarchiveDecoder(async () => {
	const response = await fetch(comicArchiveWasmUrl, {
		cache: 'force-cache',
		credentials: 'same-origin',
		redirect: 'error',
	})
	const declaredBytes = Number(response.headers.get('content-length') ?? 0)
	if (!response.ok || response.body === null
		|| (declaredBytes !== 0 && (!Number.isSafeInteger(declaredBytes) || declaredBytes > MAXIMUM_WASM_BYTES))) {
		if (response.body !== null) {
			await response.body.cancel().catch(() => {})
		}
		throw new Error('Could not load the bounded RAR3 fallback decoder.')
	}

	const wasmBytes = await globalThis.ReaderLimits.readStream(response.body, MAXIMUM_WASM_BYTES)
	return createComicArchive({ wasmBinary: wasmBytes })
})

export function getRar3FallbackDecoder() {
	return decoder
}
