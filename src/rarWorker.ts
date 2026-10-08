import '../js/reader-limits.js'
import { fromUint8Array, unrar, walkRar3Archive, walkRar5Archive } from '@mary/rar'

interface ExtractRequest {
	type: 'extract'
	arrayBuffer: ArrayBuffer
}

interface WorkerMessage {
	type: string
	[key: string]: unknown
}

interface RarWorkerScope {
	postMessage(message: WorkerMessage, transfer?: Transferable[]): void
	addEventListener(type: 'message', listener: (event: MessageEvent<ExtractRequest>) => void): void
}

const workerScope = self as unknown as RarWorkerScope

function send(message: WorkerMessage, transfer: Transferable[] = []): void {
	workerScope.postMessage(message, transfer)
}

function errorMessage(error: unknown): string {
	return error instanceof Error && error.message !== ''
		? error.message
		: 'RAR extraction failed.'
}

async function extractArchive(arrayBuffer: ArrayBuffer): Promise<void> {
	const limits = (globalThis as any).ReaderLimits
	const archiveSize = arrayBuffer.byteLength
	limits.assert(archiveSize <= limits.policy.archiveBytes, 'RAR exceeds the input limit.')
	let entryCount = 0
	let declaredBytes = 0
	const source = fromUint8Array(new Uint8Array(arrayBuffer))
	const walk = new Uint8Array(arrayBuffer)[6] === 1 ? walkRar5Archive : walkRar3Archive
	let headerCount = 0
	let preflightBytes = 0
	// The backend allocates its dictionary before yielding an entry. Validate
	// every header before calling unrar, as well as guarding each output stream.
	for await (const { block } of walk(source)) {
		limits.assert(++headerCount <= limits.policy.entries, 'RAR exceeds the header-count limit.')
		if (block.kind === 'file') {
			preflightBytes = limits.entry(block.unpackedSize, block.dataSize, preflightBytes)
			if ('compressionInfo' in block) {
				const dictionaryBytes = 128 * 1024 * 2 ** ((block.compressionInfo >> 10) & 15)
				limits.assert(dictionaryBytes <= limits.policy.entryBytes, 'RAR exceeds the dictionary memory limit.')
			}
		}
	}
	let currentBytes = 0
	let currentFileNumber = 0
	let compressedBytesRead = 0

	send({ type: 'start' })

	for await (const entry of unrar(source)) {
		limits.assert(++entryCount <= limits.policy.entries, 'RAR exceeds the entry-count limit.')
		declaredBytes = limits.entry(entry.size, entry.compressedSize, declaredBytes)
		compressedBytesRead += entry.compressedSize
		if (entry.isDirectory || entry.isSymlink) {
			continue
		}

		const fileData = await limits.readStream(entry.body, entry.size)
		limits.assert(fileData.byteLength === entry.size, 'RAR output size differs from metadata.')
		currentBytes += fileData.byteLength
		currentFileNumber += 1

		send({
			type: 'extract',
			unarchivedFile: {
				filename: entry.filename,
				fileData,
			},
		}, [fileData.buffer])

		send({
			type: 'progress',
			currentFilename: entry.filename,
			currentFileNumber,
			currentBytesUnarchivedInFile: entry.size,
			currentBytesUnarchived: Math.min(compressedBytesRead, archiveSize),
			totalUncompressedBytesInArchive: archiveSize,
			totalFilesInArchive: currentFileNumber,
			totalCompressedBytesRead: compressedBytesRead,
		})
	}

	send({
		type: 'progress',
		currentFilename: '',
		currentFileNumber,
		currentBytesUnarchivedInFile: 0,
		currentBytesUnarchived: archiveSize,
		totalUncompressedBytesInArchive: archiveSize,
		totalFilesInArchive: currentFileNumber,
		totalCompressedBytesRead: archiveSize,
	})
	send({
		type: 'finish',
		metadata: {
			backend: '@mary/rar',
			totalFiles: currentFileNumber,
			totalBytes: currentBytes,
		},
	})
}

workerScope.addEventListener('message', (event: MessageEvent<ExtractRequest>) => {
	if (event.data?.type !== 'extract' || !(event.data.arrayBuffer instanceof ArrayBuffer)) {
		send({ type: 'error', msg: 'RAR extraction worker received invalid input.' })
		return
	}

	extractArchive(event.data.arrayBuffer)
		.catch((error: unknown) => {
			send({ type: 'error', msg: errorMessage(error) })
		})
})
