import { fromUint8Array, unrar } from '@mary/rar'

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
	const archiveSize = arrayBuffer.byteLength
	let currentBytes = 0
	let currentFileNumber = 0
	let compressedBytesRead = 0

	send({ type: 'start' })

	for await (const entry of unrar(fromUint8Array(new Uint8Array(arrayBuffer)))) {
		compressedBytesRead += entry.compressedSize
		if (entry.isDirectory || entry.isSymlink) {
			continue
		}

		const fileData = await entry.bytes()
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
