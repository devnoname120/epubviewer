import '../js/reader-limits.js'
import { fromUint8Array, unrar, unrar3, walkRar3Archive, walkRar5Archive } from '@mary/rar'
import { createArchiveRangeReader } from './archiveRangeReader.js'
import { getRar3FallbackDecoder } from './rar3FallbackRuntime.js'
import { extractZipRange } from './zipRangeExtractor.js'

interface ExtractBufferRequest {
	type: 'extract'
	arrayBuffer: ArrayBuffer
}

interface ExtractRarUrlRequest {
	type: 'extract-url'
	url: string
}

interface ExtractZipUrlRequest {
	type: 'extract-zip-url'
	url: string
}

type ExtractRequest = ExtractBufferRequest | ExtractRarUrlRequest | ExtractZipUrlRequest

interface WorkerMessage {
	type: string
	[key: string]: unknown
}

interface RarWorkerScope {
	location: { origin: string }
	postMessage(message: WorkerMessage, transfer?: Transferable[]): void
	addEventListener(type: 'message', listener: (event: MessageEvent<ExtractRequest>) => void): void
}

interface Reader {
	length: number
	read(offset: number, length?: number): Promise<ReadableStream<Uint8Array>>
}

interface Rar3FileBlock {
	kind: 'file'
	attributes: number
	dataOffset: number
	dataSize: number
	fileFlags: number
	headerOffset: number
	headerSize: number
	hostOs: number
	method: number
	name: string
	unpackedSize: number
}

const workerScope = self as unknown as RarWorkerScope
const RAR3_STORED = 0x30
const RAR3_SPLIT = 0x0003
const RAR3_PASSWORD = 0x0004
const RAR3_SOLID = 0x0010
const RAR3_DIRECTORY_MASK = 0x00e0
const RAR3_VERSION = 0x0800
const RAR3_MODE_SWITCH_ERROR = 'RAR3 in-stream table refresh switched to PPMd unexpectedly'
const RAR3_PPMD_INVALID_SYMBOL_ERROR = 'RAR3 PPMd: decoder reported invalid symbol'
const RAR3_HUFFMAN_TABLE_ERROR = 'over-subscribed RAR3 Huffman table'
const RAR3_SIZE_MISMATCH_ERROR = /^RAR3 decompressed size mismatch: got (\d+) bytes, expected (\d+)$/

function send(message: WorkerMessage, transfer: Transferable[] = []): void {
	workerScope.postMessage(message, transfer)
}

function errorMessage(error: unknown): string {
	return error instanceof Error && error.message !== ''
		? error.message
		: 'RAR extraction failed.'
}

async function readExact(source: Reader, offset: number, length: number): Promise<Uint8Array> {
	const limits = (globalThis as any).ReaderLimits
	return limits.readStream(await source.read(offset, length), length)
}

async function openRarSource(request: ExtractBufferRequest | ExtractRarUrlRequest): Promise<Reader> {
	const limits = (globalThis as any).ReaderLimits
	if (request.type === 'extract') {
		limits.assert(request.arrayBuffer instanceof ArrayBuffer, 'RAR extraction worker received invalid input.')
		limits.assert(request.arrayBuffer.byteLength <= limits.policy.archiveBytes, 'RAR exceeds the buffered input limit.')
		return fromUint8Array(new Uint8Array(request.arrayBuffer)) as Reader
	}
	if (request.type === 'extract-url' && typeof request.url === 'string') {
		return createArchiveRangeReader(request.url, {
			fetch: globalThis.fetch,
			maxArchiveBytes: limits.policy.rarArchiveBytes,
			maxFetchedBytes: limits.policy.rarFetchBytes,
			origin: workerScope.location.origin,
		}) as Promise<Reader>
	}
	throw new Error('RAR extraction worker received invalid input.')
}

async function detectRar5(source: Reader): Promise<boolean> {
	const signature = await readExact(source, 0, Math.min(8, source.length))
	const rar3 = [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00]
	const rar5 = [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00]
	const matches = (expected: number[]) => expected.every((value, index) => signature[index] === value)
	if (matches(rar5)) {
		return true
	}
	if (matches(rar3)) {
		return false
	}
	throw new Error('RAR archive has an invalid or unsupported signature.')
}

async function preflightArchive(source: Reader, isRar5: boolean): Promise<Rar3FileBlock[]> {
	const limits = (globalThis as any).ReaderLimits
	const walk = isRar5 ? walkRar5Archive : walkRar3Archive
	const rar3Blocks: Rar3FileBlock[] = []
	let headerCount = 0
	let metadataBytes = 0
	let preflightBytes = 0

	for await (const { block } of walk(source as any)) {
		limits.assert(++headerCount <= limits.policy.entries, 'RAR exceeds the header-count limit.')
		limits.assert(Number.isSafeInteger(block.headerSize) && block.headerSize >= 7, 'RAR contains an invalid header size.')
		limits.assert(block.headerSize <= limits.policy.directoryBytes - metadataBytes, 'RAR exceeds the metadata limit.')
		metadataBytes += block.headerSize
		if (block.kind === 'main') {
			limits.assert((block.mainFlags & 0x01) === 0, 'Multi-volume RAR archives are unsupported.')
			if (!isRar5) {
				limits.assert((block.mainFlags & 0x0080) === 0, 'Header-encrypted RAR archives are unsupported.')
			}
		}
		if (isRar5 && block.kind === 'encryption') {
			limits.assert(false, 'Header-encrypted RAR archives are unsupported.')
		}

		if (block.kind === 'file') {
			preflightBytes = limits.entry(block.unpackedSize, block.dataSize, preflightBytes)
			limits.assert(block.dataSize <= limits.policy.entryBytes, 'RAR entry exceeds the compressed input limit.')
			if ('compressionInfo' in block) {
				limits.assert((block.blockFlags & 0x18) === 0, 'Multi-volume RAR entries are unsupported.')
				limits.assert(block.encryption === undefined, 'Encrypted RAR entries are unsupported.')
				const dictionaryBytes = 128 * 1024 * 2 ** ((block.compressionInfo >> 10) & 15)
				limits.assert(dictionaryBytes <= limits.policy.entryBytes, 'RAR exceeds the dictionary memory limit.')
			} else {
				limits.assert((block.fileFlags & RAR3_SPLIT) === 0, 'Multi-volume RAR entries are unsupported.')
				limits.assert((block.fileFlags & RAR3_PASSWORD) === 0, 'Encrypted RAR entries are unsupported.')
				if ((block.fileFlags & RAR3_VERSION) === 0) {
					rar3Blocks.push(block as Rar3FileBlock)
				}
			}
		}

		const scanned = Number.isSafeInteger(block.dataOffset) && Number.isSafeInteger(block.dataSize)
			? Math.min(source.length, block.dataOffset + block.dataSize)
			: 0
		send({
			type: 'progress',
			currentBytesUnarchived: scanned,
			totalUncompressedBytesInArchive: source.length,
		})
	}

	return rar3Blocks
}

function isRar3Directory(block: Rar3FileBlock): boolean {
	return (block.fileFlags & RAR3_DIRECTORY_MASK) === RAR3_DIRECTORY_MASK
}

function isRar3Symlink(block: Rar3FileBlock): boolean {
	return block.hostOs === 3 && (block.attributes & 0xf000) === 0xa000
}

function hasDependentRar3SolidEntry(blocks: Rar3FileBlock[], index: number): boolean {
	for (let next = index + 1; next < blocks.length; ++next) {
		const block = blocks[next]
		if (block.method === RAR3_STORED) {
			continue
		}
		return (block.fileFlags & RAR3_SOLID) !== 0
	}

	return false
}

function canUseRar3Fallback(error: unknown, blocks: Rar3FileBlock[], index: number): boolean {
	const block = blocks[index]
	const message = error instanceof Error ? error.message : ''
	const sizeMismatch = RAR3_SIZE_MISMATCH_ERROR.exec(message)
	const shortOutput = sizeMismatch !== null
		&& Number(sizeMismatch[1]) < block.unpackedSize
		&& Number(sizeMismatch[2]) === block.unpackedSize
	return error instanceof Error
		&& (message === RAR3_MODE_SWITCH_ERROR || message === RAR3_PPMD_INVALID_SYMBOL_ERROR || message === RAR3_HUFFMAN_TABLE_ERROR || shortOutput)
		&& block.method !== RAR3_STORED
		&& (block.fileFlags & RAR3_SOLID) === 0
		&& !hasDependentRar3SolidEntry(blocks, index)
}

function sendExtractedFile(
	filename: string,
	fileData: Uint8Array,
	archiveSize: number,
	compressedBytesRead: number,
	currentFileNumber: number,
): void {
	const fileBytes = fileData.byteLength
	send({
		type: 'extract',
		unarchivedFile: { fileData, filename },
	}, [fileData.buffer])
	send({
		type: 'progress',
		currentFilename: filename,
		currentFileNumber,
		currentBytesUnarchivedInFile: fileBytes,
		currentBytesUnarchived: Math.min(compressedBytesRead, archiveSize),
		totalUncompressedBytesInArchive: archiveSize,
		totalFilesInArchive: currentFileNumber,
		totalCompressedBytesRead: compressedBytesRead,
	})
}

async function extractRar5(source: Reader): Promise<{ bytes: number; files: number }> {
	const limits = (globalThis as any).ReaderLimits
	let bytes = 0
	let compressedBytesRead = 0
	let entries = 0
	let files = 0
	for await (const entry of unrar(source as any)) {
		limits.assert(++entries <= limits.policy.entries, 'RAR exceeds the entry-count limit.')
		compressedBytesRead += entry.compressedSize
		if (entry.isDirectory || entry.isSymlink) {
			continue
		}
		const fileData = await limits.readStream(entry.body, entry.size)
		limits.assert(fileData.byteLength === entry.size, 'RAR output size differs from metadata.')
		bytes += fileData.byteLength
		files += 1
		sendExtractedFile(entry.filename, fileData, source.length, compressedBytesRead, files)
	}
	return { bytes, files }
}

async function extractRar3(source: Reader, blocks: Rar3FileBlock[]): Promise<{ bytes: number; fallback: boolean; files: number }> {
	const limits = (globalThis as any).ReaderLimits
	let fallback: ReturnType<typeof getRar3FallbackDecoder> | undefined
	let blockIndex = 0
	let bytes = 0
	let compressedBytesRead = 0
	let entries = 0
	let files = 0
	let fallbackUsed = false

	for await (const entry of unrar3(source as any)) {
		limits.assert(++entries <= limits.policy.entries, 'RAR exceeds the entry-count limit.')
		const currentBlockIndex = blockIndex++
		const block = blocks[currentBlockIndex]
		const blockName = block?.name.replaceAll('\\', '/')
		limits.assert(block !== undefined
			&& blockName === entry.filename
			&& block.unpackedSize === entry.size
			&& block.dataSize === entry.compressedSize,
		'RAR extraction metadata differs from its preflight.')
		compressedBytesRead += block.dataSize
		if (entry.isDirectory || entry.isSymlink || isRar3Directory(block) || isRar3Symlink(block)) {
			continue
		}

		let fileData: Uint8Array
		try {
			fileData = await limits.readStream(entry.body, entry.size)
		} catch (error) {
			if (!canUseRar3Fallback(error, blocks, currentBlockIndex)) {
				throw error
			}

			const entryBytes = await readExact(source, block.headerOffset, block.headerSize + block.dataSize)
			const fallbackBlock = blockName === block.name ? block : { ...block, name: blockName }
			fallback ??= getRar3FallbackDecoder()
			const decoded = await fallback.decode(fallbackBlock, entryBytes)
			limits.assert(decoded.filename === entry.filename, 'RAR fallback returned an unexpected entry.')
			fileData = decoded.fileData
			fallbackUsed = true
		}
		limits.assert(fileData.byteLength === entry.size, 'RAR output size differs from metadata.')
		bytes += fileData.byteLength
		files += 1
		sendExtractedFile(entry.filename, fileData, source.length, compressedBytesRead, files)
	}
	limits.assert(blockIndex === blocks.length, 'RAR entry count differs from its preflight.')
	return { bytes, fallback: fallbackUsed, files }
}

async function extractRarArchive(request: ExtractBufferRequest | ExtractRarUrlRequest): Promise<void> {
	const source = await openRarSource(request)
	const isRar5 = await detectRar5(source)
	send({ type: 'start', archiveSize: source.length })
	const rar3Blocks = await preflightArchive(source, isRar5)
	const result = isRar5
		? { ...(await extractRar5(source)), fallback: false }
		: await extractRar3(source, rar3Blocks)

	send({
		type: 'progress',
		currentFilename: '',
		currentFileNumber: result.files,
		currentBytesUnarchivedInFile: 0,
		currentBytesUnarchived: source.length,
		totalUncompressedBytesInArchive: source.length,
		totalFilesInArchive: result.files,
		totalCompressedBytesRead: source.length,
	})
	send({
		type: 'finish',
		metadata: {
			backend: result.fallback ? '@mary/rar + libarchive' : '@mary/rar',
			totalBytes: result.bytes,
			totalFiles: result.files,
		},
	})
}

async function extractZipArchive(request: ExtractZipUrlRequest): Promise<void> {
	const limits = (globalThis as any).ReaderLimits
	const source = await createArchiveRangeReader(request.url, {
		fetch: globalThis.fetch,
		maxArchiveBytes: limits.policy.rarArchiveBytes,
		maxFetchedBytes: limits.policy.rarFetchBytes,
		origin: workerScope.location.origin,
	}) as Reader
	send({ type: 'start', archiveSize: source.length })
	const result = await extractZipRange(source, limits, {
		extract(entry: { fileData: Uint8Array; filename: string }) {
			send({ type: 'extract', unarchivedFile: entry }, [entry.fileData.buffer])
		},
		progress(progress: WorkerMessage) {
			send({ type: 'progress', ...progress })
		},
	})

	send({
		type: 'progress',
		currentFilename: '',
		currentFileNumber: result.totalFiles,
		currentBytesUnarchivedInFile: 0,
		currentBytesUnarchived: source.length,
		totalUncompressedBytesInArchive: source.length,
		totalFilesInArchive: result.totalFiles,
		totalCompressedBytesRead: source.length,
	})
	send({
		type: 'finish',
		metadata: {
			backend: 'bounded ZIP ranges',
			totalBytes: result.totalBytes,
			totalFiles: result.totalFiles,
		},
	})
}

workerScope.addEventListener('message', (event: MessageEvent<ExtractRequest>) => {
	const request = event.data
	if (request?.type !== 'extract' && request?.type !== 'extract-url' && request?.type !== 'extract-zip-url') {
		send({ type: 'error', msg: 'RAR extraction worker received invalid input.' })
		return
	}

	const extraction = request.type === 'extract-zip-url'
		? extractZipArchive(request)
		: extractRarArchive(request)
	extraction
		.catch((error: unknown) => {
			send({ type: 'error', msg: errorMessage(error) })
		})
})
