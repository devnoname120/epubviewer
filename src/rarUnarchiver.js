const RAR3_SIGNATURE = [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00]
const RAR5_SIGNATURE = [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00]

function hasSignature(arrayBuffer, signature) {
	if (!(arrayBuffer instanceof ArrayBuffer) || arrayBuffer.byteLength < signature.length) {
		return false
	}

	const bytes = new Uint8Array(arrayBuffer, 0, signature.length)
	return signature.every((value, index) => bytes[index] === value)
}

export function isRarArchive(arrayBuffer) {
	return hasSignature(arrayBuffer, RAR3_SIGNATURE) || hasSignature(arrayBuffer, RAR5_SIGNATURE)
}

function createWorker() {
	return new Worker(new URL('./rarWorker.ts', import.meta.url), {
		name: 'epubviewer-rar',
		type: 'module',
	})
}

function archiveEvent(message) {
	const event = new Event(message.type)
	for (const [key, value] of Object.entries(message)) {
		if (key !== 'type') {
			event[key] = value
		}
	}
	return event
}

export class MaryRarUnarchiver extends EventTarget {
	constructor(arrayBuffer, options = {}) {
		super()
		if (!(arrayBuffer instanceof ArrayBuffer)) {
			throw new TypeError('RAR input must be an ArrayBuffer')
		}

		this.arrayBuffer = arrayBuffer
		this.workerFactory = options.workerFactory ?? createWorker
		this.worker = null
		this.startPromise = null
	}

	start() {
		if (this.startPromise !== null) {
			return this.startPromise
		}

		this.startPromise = new Promise((resolve, reject) => {
			const worker = this.workerFactory()
			this.worker = worker
			let settled = false

			const fail = (message) => {
				if (settled) {
					return
				}
				settled = true
				const detail = typeof message === 'string' && message !== ''
					? message
					: 'RAR extraction failed.'
				this.dispatchEvent(archiveEvent({ type: 'error', msg: detail }))
				this.stop()
				reject(new Error(detail))
			}

			worker.addEventListener('message', (event) => {
				const message = event.data
				if (!message || typeof message.type !== 'string' || settled) {
					return
				}

				if (message.type === 'error') {
					fail(message.msg)
					return
				}

				this.dispatchEvent(archiveEvent(message))
				if (message.type === 'finish') {
					settled = true
					this.stop()
					resolve()
				}
			})

			worker.addEventListener('error', (event) => {
				fail(event.message)
			})
			worker.addEventListener('messageerror', () => {
				fail('Could not read the RAR extraction worker response.')
			})

			const arrayBuffer = this.arrayBuffer
			this.arrayBuffer = null
			try {
				worker.postMessage({ type: 'extract', arrayBuffer }, [arrayBuffer])
			} catch (error) {
				fail(error instanceof Error ? error.message : '')
			}
		})

		return this.startPromise
	}

	stop() {
		if (this.worker !== null) {
			this.worker.terminate()
			this.worker = null
		}
	}
}

export function createComicUnarchiver(arrayBuffer, options = {}) {
	const { nonRarFactory, workerFactory } = options
	if (isRarArchive(arrayBuffer)) {
		return new MaryRarUnarchiver(arrayBuffer, { workerFactory })
	}

	if (typeof nonRarFactory !== 'function') {
		throw new TypeError('A non-RAR archive factory is required')
	}

	return nonRarFactory(arrayBuffer)
}
