function positiveSafeInteger(value, description) {
	if (!Number.isSafeInteger(value) || value < 1) {
		throw new RangeError(`${description} must be a positive safe integer.`)
	}
}

function parseContentRange(value) {
	const match = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(value ?? '')
	if (match === null) {
		throw new Error('Archive server returned an invalid range response.')
	}

	const start = Number(match[1])
	const end = Number(match[2])
	const total = Number(match[3])
	if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || !Number.isSafeInteger(total)
		|| start < 0 || end < start || total < 1 || end >= total) {
		throw new Error('Archive server returned an invalid range response.')
	}

	return { end, start, total }
}

function exactLengthStream(source, expectedBytes) {
	const reader = source.getReader()
	let delivered = 0

	return new ReadableStream({
		async pull(controller) {
			while (true) {
				const { done, value } = await reader.read()
				if (done) {
					if (delivered !== expectedBytes) {
						controller.error(new Error('Archive server returned an invalid range body length.'))
					} else {
						controller.close()
					}
					return
				}

				if (!(value instanceof Uint8Array) || delivered + value.byteLength > expectedBytes) {
					await reader.cancel()
					controller.error(new Error('Archive server returned an invalid range body length.'))
					return
				}
				if (value.byteLength === 0) {
					continue
				}

				delivered += value.byteLength
				controller.enqueue(value)
				return
			}
		},
		cancel(reason) {
			return reader.cancel(reason)
		},
	})
}

export async function createArchiveRangeReader(input, options) {
	const fetchRange = options?.fetch ?? globalThis.fetch
	if (typeof fetchRange !== 'function') {
		throw new TypeError('Archive range fetching is unavailable.')
	}
	positiveSafeInteger(options?.maxArchiveBytes, 'Archive limit')
	positiveSafeInteger(options?.maxFetchedBytes, 'Archive fetch limit')

	const origin = new URL(options?.origin).origin
	const url = new URL(input, origin)
	if (url.origin !== origin) {
		throw new Error('Archive input must use the same origin as the viewer.')
	}

	let fetchedBytes = 0
	let archiveBytes = null

	async function request(start, end) {
		const expectedBytes = end - start + 1
		if (fetchedBytes > options.maxFetchedBytes - expectedBytes) {
			throw new Error('Archive range requests exceed the fetch limit.')
		}
		fetchedBytes += expectedBytes

		const response = await fetchRange(url.href, {
			cache: 'no-store',
			credentials: 'same-origin',
			headers: { Range: `bytes=${start}-${end}` },
			method: 'GET',
			redirect: 'error',
		})
		try {
			if (!response?.ok || response.status !== 206 || response.body === null) {
				throw new Error('Archive server did not return a valid range response.')
			}
			if (response.url !== '' && new URL(response.url).origin !== origin) {
				throw new Error('Archive server returned a cross-origin range response.')
			}
			const encoding = response.headers.get('content-encoding')
			if (encoding !== null && encoding.toLowerCase() !== 'identity') {
				throw new Error('Archive server returned an encoded range response.')
			}

			const range = parseContentRange(response.headers.get('content-range'))
			if (range.start !== start || range.end !== end || (archiveBytes !== null && range.total !== archiveBytes)) {
				throw new Error('Archive server returned an inconsistent range response.')
			}
			const contentLength = Number(response.headers.get('content-length'))
			if (!Number.isSafeInteger(contentLength) || contentLength !== expectedBytes) {
				throw new Error('Archive server returned an invalid range response length.')
			}

			return { stream: exactLengthStream(response.body, expectedBytes), total: range.total }
		} catch (error) {
			if (response?.body !== null && typeof response?.body?.cancel === 'function') {
				await response.body.cancel().catch(() => {})
			}
			throw error
		}
	}

	const probe = await request(0, 1)
	archiveBytes = probe.total
	if (archiveBytes > options.maxArchiveBytes) {
		await probe.stream.cancel()
		throw new Error('Archive exceeds the remote input limit.')
	}
	for await (const _chunk of probe.stream) {
		// The validated probe must be consumed so its response body cannot remain open.
	}

	return {
		length: archiveBytes,
		async read(offset, length) {
			if (!Number.isSafeInteger(offset) || offset < 0) {
				throw new RangeError('Archive byte range has an invalid offset.')
			}
			const bytes = length === undefined ? archiveBytes - offset : length
			if (!Number.isSafeInteger(bytes) || bytes < 1 || offset >= archiveBytes || bytes > archiveBytes - offset) {
				throw new RangeError('Archive byte range is outside the archive.')
			}

			return (await request(offset, offset + bytes - 1)).stream
		},
	}
}
