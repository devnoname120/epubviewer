import { inflate } from '../js/bitjs/v1.2.6/archive/inflate.js'

const CENTRAL_FILE_HEADER_SIGNATURE = 0x02014b50
const DATA_DESCRIPTOR_SIGNATURE = 0x08074b50
const END_OF_CENTRAL_DIRECTORY_SIGNATURE = 0x06054b50
const LOCAL_FILE_HEADER_SIGNATURE = 0x04034b50
const ZIP_TAIL_BYTES = 65_557
const ZIP64_U16 = 0xffff
const ZIP64_U32 = 0xffffffff
const DATA_DESCRIPTOR_FLAG = 1 << 3
const UTF8_FLAG = 1 << 11
const ENCRYPTION_FLAGS = 0x0001 | 0x0040 | 0x2000

const crcTable = (() => {
	const table = new Uint32Array(256)
	for (let value = 0; value < table.length; value++) {
		let crc = value
		for (let bit = 0; bit < 8; bit++) {
			crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0)
		}
		table[value] = crc >>> 0
	}
	return table
})()

function crc32(bytes) {
	let crc = 0xffffffff
	for (const byte of bytes) {
		crc = (crc >>> 8) ^ crcTable[(crc ^ byte) & 0xff]
	}
	return (crc ^ 0xffffffff) >>> 0
}

function byteView(bytes) {
	return new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
}

function safeEnd(offset, length, maximum, message, limits) {
	limits.assert(Number.isSafeInteger(offset) && Number.isSafeInteger(length)
		&& offset >= 0 && length >= 0 && offset <= maximum - length, message)
	return offset + length
}

async function readExact(source, limits, offset, length) {
	if (length === 0) {
		return new Uint8Array()
	}
	return limits.readStream(await source.read(offset, length), length)
}

function decodeLegacyName(bytes) {
	let result = ''
	for (const byte of bytes) {
		result += String.fromCharCode(byte)
	}
	return result
}

function decodeName(bytes, flags) {
	const name = (flags & UTF8_FLAG) !== 0
		? new TextDecoder('utf-8').decode(bytes)
		: decodeLegacyName(bytes)
	return name.replaceAll('\\', '/')
}

function bytesEqual(left, right) {
	if (left.byteLength !== right.byteLength) {
		return false
	}
	for (let index = 0; index < left.byteLength; index++) {
		if (left[index] !== right[index]) {
			return false
		}
	}
	return true
}

function findEndRecord(tail, tailOffset, archiveLength, limits) {
	const view = byteView(tail)
	const matches = []
	for (let offset = tail.byteLength - 22; offset >= 0; offset--) {
		if (view.getUint32(offset, true) !== END_OF_CENTRAL_DIRECTORY_SIGNATURE) {
			continue
		}
		const commentLength = view.getUint16(offset + 20, true)
		if (offset + 22 + commentLength === tail.byteLength) {
			matches.push(offset)
		}
	}
	limits.assert(matches.length === 1, 'ZIP end record is invalid or ambiguous.')
	const offset = matches[0]
	const absoluteOffset = tailOffset + offset
	limits.assert(absoluteOffset + 22 + view.getUint16(offset + 20, true) === archiveLength,
		'ZIP end record is inconsistent.')
	return { absoluteOffset, offset, view }
}

function parseEndRecord(record, limits) {
	const { absoluteOffset, offset, view } = record
	const disk = view.getUint16(offset + 4, true)
	const directoryDisk = view.getUint16(offset + 6, true)
	const diskEntries = view.getUint16(offset + 8, true)
	const entries = view.getUint16(offset + 10, true)
	const directorySize = view.getUint32(offset + 12, true)
	const directoryOffset = view.getUint32(offset + 16, true)

	limits.assert(entries !== ZIP64_U16 && directorySize !== ZIP64_U32 && directoryOffset !== ZIP64_U32,
		'ZIP64 archives are unsupported by the interactive reader.')
	limits.assert(disk === 0 && directoryDisk === 0 && diskEntries === entries,
		'Multi-disk ZIP archives are unsupported.')
	limits.assert(entries <= limits.policy.entries && directorySize <= limits.policy.directoryBytes,
		'ZIP central directory exceeds limits.')
	limits.assert(directoryOffset + directorySize === absoluteOffset,
		'ZIP central directory is inconsistent.')

	return { directoryOffset, directorySize, entries }
}

function parseCentralDirectory(bytes, metadata, limits) {
	const view = byteView(bytes)
	const entries = []
	const offsets = new Set()
	let offset = 0
	let total = 0

	while (offset < bytes.byteLength) {
		limits.assert(entries.length < metadata.entries && offset <= bytes.byteLength - 46
			&& view.getUint32(offset, true) === CENTRAL_FILE_HEADER_SIGNATURE,
		'Invalid ZIP central directory.')

		const madeBy = view.getUint16(offset + 4, true)
		const flags = view.getUint16(offset + 8, true)
		const method = view.getUint16(offset + 10, true)
		const crc = view.getUint32(offset + 16, true)
		const compressedSize = view.getUint32(offset + 20, true)
		const uncompressedSize = view.getUint32(offset + 24, true)
		const nameLength = view.getUint16(offset + 28, true)
		const extraLength = view.getUint16(offset + 30, true)
		const commentLength = view.getUint16(offset + 32, true)
		const diskStart = view.getUint16(offset + 34, true)
		const externalAttributes = view.getUint32(offset + 38, true)
		const localOffset = view.getUint32(offset + 42, true)
		const end = safeEnd(offset + 46, nameLength + extraLength + commentLength,
			bytes.byteLength, 'Invalid ZIP central directory entry.', limits)
		const rawName = bytes.slice(offset + 46, offset + 46 + nameLength)
		const name = decodeName(rawName, flags)

		limits.assert(name !== '' && !name.includes('\0'), 'ZIP entry name is invalid.')
		limits.assert((flags & ENCRYPTION_FLAGS) === 0, 'Encrypted ZIP entries are unsupported.')
		limits.assert(method === 0 || method === 8, 'ZIP compression method is unsupported.')
		limits.assert(diskStart === 0, 'Multi-disk ZIP archives are unsupported.')
		limits.assert(localOffset < metadata.directoryOffset, 'ZIP local header offset is invalid.')
		limits.assert(!offsets.has(localOffset), 'ZIP contains a duplicate local header offset.')
		limits.assert(compressedSize <= limits.policy.entryBytes, 'ZIP entry exceeds the compressed input limit.')
		limits.assert(method !== 0 || compressedSize === uncompressedSize,
			'Stored ZIP entry has inconsistent sizes.')

		offsets.add(localOffset)
		total = limits.entry(uncompressedSize, compressedSize, total)
		entries.push({
			compressedSize,
			crc,
			externalAttributes,
			flags,
			hostOs: madeBy >>> 8,
			localOffset,
			method,
			name,
			rawName,
			uncompressedSize,
		})
		offset = end
	}

	limits.assert(offset === bytes.byteLength && entries.length === metadata.entries,
		'Inconsistent ZIP entry count.')
	return entries
}

async function validateDescriptor(source, limits, entry, descriptorOffset, directoryOffset) {
	const first = await readExact(source, limits, descriptorOffset, 4)
	const firstValue = byteView(first).getUint32(0, true)
	let crc = firstValue
	let sizesOffset = descriptorOffset + 4
	let descriptorSize = 12
	if (firstValue === DATA_DESCRIPTOR_SIGNATURE) {
		const checksum = await readExact(source, limits, descriptorOffset + 4, 4)
		crc = byteView(checksum).getUint32(0, true)
		sizesOffset += 4
		descriptorSize = 16
	}
	const sizes = await readExact(source, limits, sizesOffset, 8)
	const sizesView = byteView(sizes)
	limits.assert(crc === entry.crc
		&& sizesView.getUint32(0, true) === entry.compressedSize
		&& sizesView.getUint32(4, true) === entry.uncompressedSize,
	'Invalid ZIP data descriptor.')
	limits.assert(descriptorOffset <= directoryOffset - descriptorSize,
		'ZIP data descriptor overlaps the central directory.')
	return descriptorSize
}

async function preflightLocalEntry(source, limits, entry, directoryOffset) {
	const prefix = await readExact(source, limits, entry.localOffset, 30)
	const view = byteView(prefix)
	limits.assert(view.getUint32(0, true) === LOCAL_FILE_HEADER_SIGNATURE,
		'ZIP local header is invalid.')

	const localFlags = view.getUint16(6, true)
	const localMethod = view.getUint16(8, true)
	const localCrc = view.getUint32(14, true)
	const localCompressedSize = view.getUint32(18, true)
	const localUncompressedSize = view.getUint32(22, true)
	const nameLength = view.getUint16(26, true)
	const extraLength = view.getUint16(28, true)
	const variableLength = nameLength + extraLength
	const variableEnd = safeEnd(entry.localOffset + 30, variableLength, directoryOffset,
		'ZIP local header exceeds the data area.', limits)
	const variable = await readExact(source, limits, entry.localOffset + 30, variableLength)
	const rawName = variable.subarray(0, nameLength)

	limits.assert(localFlags === entry.flags && localMethod === entry.method
		&& bytesEqual(rawName, entry.rawName), 'ZIP local header differs from the central directory.')
	if ((entry.flags & DATA_DESCRIPTOR_FLAG) === 0) {
		limits.assert(localCrc === entry.crc
			&& localCompressedSize === entry.compressedSize
			&& localUncompressedSize === entry.uncompressedSize,
		'ZIP local header differs from the central directory.')
	} else {
		limits.assert((localCrc === 0 || localCrc === entry.crc)
			&& (localCompressedSize === 0 || localCompressedSize === entry.compressedSize)
			&& (localUncompressedSize === 0 || localUncompressedSize === entry.uncompressedSize),
		'ZIP local header differs from the central directory.')
	}

	const dataOffset = variableEnd
	const dataEnd = safeEnd(dataOffset, entry.compressedSize, directoryOffset,
		'ZIP entry data exceeds the data area.', limits)
	let descriptorSize = 0
	if ((entry.flags & DATA_DESCRIPTOR_FLAG) !== 0) {
		descriptorSize = await validateDescriptor(source, limits, entry, dataEnd, directoryOffset)
	}

	return { ...entry, dataOffset, descriptorSize }
}

function isDirectory(entry) {
	return entry.name.endsWith('/') || (entry.externalAttributes & 0x10) !== 0
}

function isSymlink(entry) {
	const mode = entry.externalAttributes >>> 16
	return entry.hostOs === 3 && (mode & 0xf000) === 0xa000
}

export async function extractZipRange(source, limits, sink = {}) {
	limits.assert(source && Number.isSafeInteger(source.length) && source.length >= 22,
		'ZIP archive is truncated.')
	const tailLength = Math.min(source.length, ZIP_TAIL_BYTES)
	const tailOffset = source.length - tailLength
	const tail = await readExact(source, limits, tailOffset, tailLength)
	const endRecord = parseEndRecord(findEndRecord(tail, tailOffset, source.length, limits), limits)
	const directoryBytes = await readExact(source, limits,
		endRecord.directoryOffset, endRecord.directorySize)
	const centralEntries = parseCentralDirectory(directoryBytes, endRecord, limits)
	const entries = []
	for (const entry of centralEntries) {
		entries.push(await preflightLocalEntry(source, limits, entry, endRecord.directoryOffset))
	}

	const regularFiles = entries.filter((entry) => !isDirectory(entry) && !isSymlink(entry))
	let compressedBytesRead = 0
	let totalBytes = 0
	let totalFiles = 0
	for (const entry of regularFiles) {
		const compressed = await readExact(source, limits, entry.dataOffset, entry.compressedSize)
		const output = entry.method === 0
			? compressed
			: await inflate(compressed, entry.uncompressedSize)
		limits.assert(output.byteLength === entry.uncompressedSize,
			'ZIP output size differs from metadata.')
		limits.assert(crc32(output) === entry.crc, 'ZIP entry CRC32 differs from metadata.')

		compressedBytesRead += entry.compressedSize
		totalBytes += output.byteLength
		totalFiles += 1
		await sink.extract?.({ filename: entry.name, fileData: output }, entry)
		await sink.progress?.({
			currentBytesUnarchived: Math.min(entry.dataOffset + entry.compressedSize, source.length),
			currentBytesUnarchivedInFile: output.byteLength,
			currentFilename: entry.name,
			currentFileNumber: totalFiles,
			totalCompressedBytesRead: compressedBytesRead,
			totalFilesInArchive: regularFiles.length,
			totalUncompressedBytesInArchive: source.length,
		})
	}

	return { totalBytes, totalFiles }
}
