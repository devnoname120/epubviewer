const RAR3_SIGNATURE = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00])
const RAR3_MAIN_HEADER = Uint8Array.from([0xcf, 0x90, 0x73, 0x00, 0x00, 0x0d, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00])
const RAR3_END_HEADER = Uint8Array.from([0xc4, 0x3d, 0x7b, 0x00, 0x40, 0x07, 0x00])
const MINIMUM_LIBARCHIVE_VERSION = 3_008_000

function safeEntryInteger(value, description) {
	if (!Number.isSafeInteger(value) || value < 0) {
		throw new Error(`RAR3 ${description} is invalid.`)
	}
}

export function buildSingleEntryRar3(block, entryBytes) {
	if (!(entryBytes instanceof Uint8Array)) {
		throw new TypeError('RAR3 entry bytes must be a Uint8Array.')
	}
	safeEntryInteger(block?.headerSize, 'header size')
	safeEntryInteger(block?.dataSize, 'compressed size')
	if (block.headerSize < 7) {
		throw new Error('RAR3 entry header is truncated.')
	}
	if (entryBytes.byteLength !== block.headerSize + block.dataSize) {
		throw new Error('RAR3 entry bytes do not match the declared header and data sizes.')
	}

	const result = new Uint8Array(
		RAR3_SIGNATURE.byteLength + RAR3_MAIN_HEADER.byteLength + entryBytes.byteLength + RAR3_END_HEADER.byteLength,
	)
	let offset = 0
	for (const part of [RAR3_SIGNATURE, RAR3_MAIN_HEADER, entryBytes, RAR3_END_HEADER]) {
		result.set(part, offset)
		offset += part.byteLength
	}
	return result
}

function moduleError(module, fallback) {
	const pointer = module._comic_archive_last_error()
	const message = pointer === 0 ? '' : module.UTF8ToString(pointer)
	return new Error(message || fallback)
}

export function createRar3LibarchiveDecoder(loadModule) {
	if (typeof loadModule !== 'function') {
		throw new TypeError('RAR3 fallback requires a libarchive module loader.')
	}

	let modulePromise
	const decoder = {
		version: null,
		async decode(block, entryBytes) {
			safeEntryInteger(block?.unpackedSize, 'unpacked size')
			if (block.unpackedSize < 1 || typeof block.name !== 'string' || block.name === '') {
				throw new Error('RAR3 fallback entry metadata is invalid.')
			}
			const synthetic = buildSingleEntryRar3(block, entryBytes)
			modulePromise ??= Promise.resolve(loadModule())
			const module = await modulePromise
			if (typeof module?._comic_archive_version_number !== 'function') {
				throw new Error('RAR3 fallback libarchive module is incomplete.')
			}
			const version = module._comic_archive_version_number()
			if (!Number.isInteger(version) || version < MINIMUM_LIBARCHIVE_VERSION) {
				throw new Error('RAR3 fallback libarchive version is unsupported.')
			}
			decoder.version = version

			const inputPointer = module._malloc(synthetic.byteLength)
			if (inputPointer === 0) {
				throw new Error('RAR3 fallback could not allocate its bounded input buffer.')
			}
			module.HEAPU8.set(synthetic, inputPointer)
			let handle = 0
			let outputPointer = 0
			try {
				handle = module._comic_archive_open(inputPointer, synthetic.byteLength)
				if (handle === 0) {
					throw moduleError(module, 'RAR3 fallback could not open the selected entry.')
				}
				if (module._comic_archive_next(handle) !== 1) {
					throw moduleError(module, 'RAR3 fallback did not find the selected entry.')
					}
					const pathnamePointer = module._comic_archive_entry_path(handle)
					const pathname = pathnamePointer === 0 ? '' : module.UTF8ToString(pathnamePointer)
					const normalizedPathname = pathname.replaceAll('\\', '/')
					const normalizedBlockName = block.name.replaceAll('\\', '/')
					if (normalizedPathname !== normalizedBlockName
						|| module._comic_archive_entry_size(handle) !== block.unpackedSize
					|| module._comic_archive_entry_is_regular(handle) !== 1
					|| module._comic_archive_entry_is_encrypted(handle) !== 0) {
					throw new Error('RAR3 fallback entry metadata differs from the preflighted archive.')
				}

				outputPointer = module._malloc(block.unpackedSize)
				if (outputPointer === 0) {
					throw new Error('RAR3 fallback could not allocate its bounded output buffer.')
				}
				if (module._comic_archive_read_exact(handle, outputPointer, block.unpackedSize) !== 1) {
					throw moduleError(module, 'RAR3 fallback could not decode the selected entry.')
				}
				if (module._comic_archive_next(handle) !== 0) {
					throw new Error('RAR3 fallback synthetic archive contains more than one entry.')
				}

					return {
						filename: normalizedPathname,
					fileData: module.HEAPU8.slice(outputPointer, outputPointer + block.unpackedSize),
				}
			} finally {
				if (outputPointer !== 0) {
					module._free(outputPointer)
				}
				if (handle !== 0) {
					module._comic_archive_close(handle)
				}
				module._free(inputPointer)
			}
		},
	}

	return decoder
}
