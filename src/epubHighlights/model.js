export const HIGHLIGHT_PALETTE = Object.freeze({
	yellow: '#f6d94a',
	green: '#75d69c',
	blue: '#73b8f4',
	pink: '#f29ac2',
	orange: '#f4a261',
})

const TEXT_NODE = 3
const HIGHLIGHT_VERSION = 1
const MAX_ID_CHARACTERS = 128
const SAFE_ID = /^[A-Za-z0-9_-]+$/

function policyOrThrow(policy) {
	if (!policy || typeof policy !== 'object') {
		throw new TypeError('EPUB highlight limits are unavailable.')
	}
	return policy
}

function isPlainObject(value) {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		return false
	}
	const prototype = Object.getPrototypeOf(value)
	return prototype === Object.prototype || prototype === null
}

function isBoundedString(value, maximum, allowEmpty = false) {
	return typeof value === 'string'
		&& (allowEmpty || value.length > 0)
		&& value.length <= maximum
}

function isCfi(value, policy) {
	return isBoundedString(value, policy.highlightCfiCharacters)
		&& value.startsWith('epubcfi(')
		&& value.endsWith(')')
}

function isFiniteTimestamp(value) {
	return typeof value === 'number' && Number.isFinite(value) && value >= 0
}

function isSafeId(value) {
	return isBoundedString(value, MAX_ID_CHARACTERS) && SAFE_ID.test(value)
}

function normalizeAnchor(value, policy) {
	if (!isPlainObject(value)
		|| value.version !== HIGHLIGHT_VERSION
		|| !Number.isSafeInteger(value.spinePos)
		|| value.spinePos < 0
		|| !isCfi(value.start, policy)
		|| !isCfi(value.end, policy)) {
		return null
	}

	return {
		version: HIGHLIGHT_VERSION,
		spinePos: value.spinePos,
		start: value.start,
		end: value.end,
	}
}

export function highlightContentBytes(value) {
	try {
		return new TextEncoder().encode(JSON.stringify(value)).byteLength
	} catch {
		return Number.POSITIVE_INFINITY
	}
}

export function excerptSelection(text, maximum) {
	if (typeof text !== 'string' || !Number.isSafeInteger(maximum) || maximum < 1) {
		return ''
	}
	const normalized = text.replace(/\s+/gu, ' ').trim()
	if (normalized.length <= maximum) {
		return normalized
	}
	if (maximum === 1) {
		return '…'
	}
	return `${normalized.slice(0, maximum - 1).trimEnd()}…`
}

export function normalizeHighlight(value, inputPolicy = globalThis.ReaderLimits?.policy) {
	const policy = policyOrThrow(inputPolicy)
	if (!isPlainObject(value)
		|| value.type !== 'highlight'
		|| !isSafeId(value.id)
		|| !isFiniteTimestamp(value.date)
		|| !isFiniteTimestamp(value.edited)
		|| !isBoundedString(value.body, policy.highlightTextCharacters, true)
		|| !Object.prototype.hasOwnProperty.call(HIGHLIGHT_PALETTE, value.color)) {
		return null
	}

	const anchor = normalizeAnchor(value.anchor, policy)
	if (anchor === null) {
		return null
	}

	const normalized = {
		id: value.id,
		type: 'highlight',
		date: value.date,
		edited: value.edited,
		anchor,
		body: value.body,
		color: value.color,
	}

	return highlightContentBytes(normalized) <= policy.highlightContentBytes
		? normalized
		: null
}

export function sameHighlightAnchor(left, right) {
	return Boolean(left && right)
		&& left.version === HIGHLIGHT_VERSION
		&& right.version === HIGHLIGHT_VERSION
		&& left.spinePos === right.spinePos
		&& left.start === right.start
		&& left.end === right.end
}

function cfiInstance(EpubCFI) {
	if (typeof EpubCFI === 'function') {
		return new EpubCFI()
	}
	if (EpubCFI && typeof EpubCFI === 'object') {
		return EpubCFI
	}
	throw new TypeError('EPUB CFI support is unavailable.')
}

export function compareHighlights(left, right, EpubCFI) {
	const spineDifference = left.anchor.spinePos - right.anchor.spinePos
	if (spineDifference !== 0) {
		return spineDifference
	}

	try {
		const cfi = cfiInstance(EpubCFI)
		if (typeof cfi.compare === 'function') {
			return cfi.compare(left.anchor.start, right.anchor.start)
		}
	} catch {
		// A stable lexical order keeps a malformed record from breaking the sidebar.
	}
	return left.anchor.start.localeCompare(right.anchor.start)
}

function rangeDocument(range) {
	return range?.startContainer?.ownerDocument
		?? range?.document
		?? range?.commonAncestorContainer?.ownerDocument
		?? null
}

function intersectingTextNodes(range, document) {
	const root = range.commonAncestorContainer
	if (!root) {
		return []
	}
	if (root.nodeType === TEXT_NODE) {
		return range.intersectsNode(root) ? [root] : []
	}

	const showText = document.defaultView?.NodeFilter?.SHOW_TEXT ?? 4
	const walker = document.createTreeWalker(root, showText)
	const nodes = []
	let node
	while ((node = walker.nextNode()) !== null) {
		try {
			if (node.nodeType === TEXT_NODE && node.length > 0 && range.intersectsNode(node)) {
				nodes.push(node)
			}
		} catch {
			// Detached nodes are ignored; selection capture will fail closed if none remain.
		}
	}
	return nodes
}

export function rangeToHighlightAnchor(
	range,
	chapter,
	EpubCFI,
	inputPolicy = globalThis.ReaderLimits?.policy,
) {
	const policy = policyOrThrow(inputPolicy)
	if (!range || range.collapsed || !chapter || !Number.isSafeInteger(chapter.spinePos)
		|| chapter.spinePos < 0 || typeof chapter.cfiBase !== 'string') {
		return null
	}

	const selectedText = String(range.toString())
	if (selectedText.trim() === '' || selectedText.length > policy.pageTextCharacters) {
		return null
	}

	const document = rangeDocument(range)
	if (!document || typeof document.createTreeWalker !== 'function') {
		return null
	}
	const nodes = intersectingTextNodes(range, document)
	if (nodes.length === 0) {
		return null
	}

	const first = nodes[0]
	const last = nodes[nodes.length - 1]
	const startOffset = range.startContainer === first ? range.startOffset : 0
	const endOffset = range.endContainer === last ? range.endOffset : last.length
	if (!Number.isSafeInteger(startOffset) || !Number.isSafeInteger(endOffset)
		|| startOffset < 0 || endOffset < 0
		|| startOffset > first.length || endOffset > last.length
		|| (first === last && startOffset >= endOffset)) {
		return null
	}

	try {
		const cfi = cfiInstance(EpubCFI)
		const start = cfi.generateCfiFromTextNode(first, startOffset, chapter.cfiBase)
		const end = cfi.generateCfiFromTextNode(last, endOffset, chapter.cfiBase)
		if (!isCfi(start, policy) || !isCfi(end, policy)) {
			return null
		}
		return {
			version: HIGHLIGHT_VERSION,
			spinePos: chapter.spinePos,
			start,
			end,
		}
	} catch {
		return null
	}
}

function resolvePoint(value, expectedSpinePos, document, cfi) {
	const parsed = cfi.parse(value)
	if (!parsed || parsed.spinePos !== expectedSpinePos || !Array.isArray(parsed.steps)
		|| parsed.steps.length === 0 || !Number.isSafeInteger(parsed.characterOffset)
		|| parsed.characterOffset < 0) {
		return null
	}

	const step = parsed.steps[parsed.steps.length - 1]
	if (!step || step.type !== 'text' || !Number.isSafeInteger(step.index) || step.index < 0) {
		return null
	}
	const parent = cfi.findParent(parsed, document)
	const textNodes = Array.from(parent?.childNodes ?? [])
		.filter((node) => node?.nodeType === TEXT_NODE)
	const node = textNodes[step.index]
	if (!node || node.nodeType !== TEXT_NODE || parsed.characterOffset > node.length) {
		return null
	}
	return { node, offset: parsed.characterOffset }
}

export function highlightAnchorToRange(
	value,
	document,
	EpubCFI,
	inputPolicy = globalThis.ReaderLimits?.policy,
) {
	const policy = policyOrThrow(inputPolicy)
	const anchor = normalizeAnchor(value, policy)
	if (anchor === null || !document || typeof document.createRange !== 'function') {
		return null
	}

	try {
		const cfi = cfiInstance(EpubCFI)
		const start = resolvePoint(anchor.start, anchor.spinePos, document, cfi)
		const end = resolvePoint(anchor.end, anchor.spinePos, document, cfi)
		if (start === null || end === null) {
			return null
		}
		const range = document.createRange()
		range.setStart(start.node, start.offset)
		range.setEnd(end.node, end.offset)
		if (range.collapsed || String(range.toString()).trim() === '') {
			return null
		}
		return range
	} catch {
		return null
	}
}
