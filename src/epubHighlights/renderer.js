import {
	HIGHLIGHT_PALETTE,
	highlightAnchorToRange,
	normalizeHighlight,
} from './model.js'

const STYLE_ID = 'epubviewer-highlight-style'
const LAYER_ID = 'epubviewer-highlight-layer'
const REGISTRY_PREFIX = 'epubviewer-'

function highlightStyle(palette) {
	const rules = [
		'.epubviewer-highlight-layer { position: absolute; inset: 0; pointer-events: none; z-index: 2147483000; }',
		'.epubviewer-highlight-rect { position: absolute; border-radius: 2px; opacity: 0.42; mix-blend-mode: multiply; }',
	]
	for (const [name, color] of Object.entries(palette)) {
		rules.push(`::highlight(${REGISTRY_PREFIX}${name}) { background-color: ${color}; color: inherit; }`)
		rules.push(`.epubviewer-highlight-${name} { background-color: ${color}; }`)
	}
	return rules.join('\n')
}

function safeCall(callback, value) {
	try {
		callback(value)
	} catch {
		// Rendering errors are already bounded and should not cascade through epub.js.
	}
}

function nativeHighlightSupport(document) {
	const window = document?.defaultView
	const registry = window?.CSS?.highlights
	return Boolean(registry
		&& typeof registry.set === 'function'
		&& typeof registry.delete === 'function'
		&& typeof window.Highlight === 'function')
}

function positiveRect(rect) {
	return rect
		&& Number.isFinite(rect.left)
		&& Number.isFinite(rect.top)
		&& Number.isFinite(rect.width)
		&& Number.isFinite(rect.height)
		&& rect.width > 0
		&& rect.height > 0
}

export class EpubHighlightRenderer {
	constructor({
		EpubCFI,
		palette = HIGHLIGHT_PALETTE,
		policy = globalThis.ReaderLimits?.policy,
		onError = () => {},
		resolveRange = highlightAnchorToRange,
	}) {
		if (!EpubCFI || !policy || typeof policy !== 'object'
			|| !palette || typeof palette !== 'object'
			|| typeof resolveRange !== 'function') {
			throw new TypeError('EPUB highlight rendering is unavailable.')
		}
		this.EpubCFI = EpubCFI
		this.palette = palette
		this.policy = policy
		this.onError = onError
		this.resolveRange = resolveRange
		this.currentWindow = null
		this.nativeNames = new Set()
		this.overlay = null
		this.frameId = null
		this.frameWindow = null
		this.scheduled = null
	}

	render(document, chapter, renderer, values) {
		this._clearOutput()
		this.currentWindow = document?.defaultView ?? null
		if (!document?.head || !document?.body || !chapter
			|| !Number.isSafeInteger(chapter.spinePos)) {
			return { mode: 'none', rendered: 0, skipped: Array.isArray(values) ? values.length : 0 }
		}

		this._ensureStyle(document)
		const prepared = []
		let skipped = 0
		for (const value of Array.isArray(values) ? values : []) {
			const highlight = normalizeHighlight(value, this.policy)
			if (highlight === null || highlight.anchor.spinePos !== chapter.spinePos) {
				skipped += 1
				continue
			}
			let range = null
			try {
				range = this.resolveRange(
					highlight.anchor,
					document,
					this.EpubCFI,
					highlight,
				)
			} catch {
				range = null
			}
			if (range === null) {
				skipped += 1
				continue
			}
			prepared.push({ highlight, range })
		}

		return nativeHighlightSupport(document)
			? this._renderNative(document, prepared, skipped)
			: this._renderOverlay(document, renderer, prepared, skipped)
	}

	schedule(document, chapter, renderer, values) {
		this.scheduled = { document, chapter, renderer, values }
		if (this.frameId !== null) {
			return
		}
		const window = document?.defaultView ?? globalThis
		const request = typeof window.requestAnimationFrame === 'function'
			? window.requestAnimationFrame.bind(window)
			: (callback) => globalThis.setTimeout(callback, 0)
		this.frameWindow = window
		this.frameId = request(() => {
			this.frameId = null
			this.frameWindow = null
			const next = this.scheduled
			this.scheduled = null
			if (next !== null) {
				this.render(next.document, next.chapter, next.renderer, next.values)
			}
		})
	}

	clear() {
		if (this.frameId !== null) {
			const cancel = typeof this.frameWindow?.cancelAnimationFrame === 'function'
				? this.frameWindow.cancelAnimationFrame.bind(this.frameWindow)
				: globalThis.clearTimeout
			cancel(this.frameId)
		}
		this.frameId = null
		this.frameWindow = null
		this.scheduled = null
		this._clearOutput()
		this.currentWindow = null
	}

	_ensureStyle(document) {
		if (document.getElementById?.(STYLE_ID)) {
			return
		}
		const style = document.createElement('style')
		style.id = STYLE_ID
		style.textContent = highlightStyle(this.palette)
		document.head.appendChild(style)
	}

	_renderNative(document, prepared, skipped) {
		const registry = document.defaultView.CSS.highlights
		const Highlight = document.defaultView.Highlight
		const groups = new Map()
		for (const { highlight, range } of prepared) {
			let group = groups.get(highlight.color)
			if (!group) {
				group = new Highlight()
				groups.set(highlight.color, group)
			}
			group.add(range)
		}

		for (const [color, group] of groups) {
			const name = `${REGISTRY_PREFIX}${color}`
			registry.set(name, group)
			this.nativeNames.add(name)
		}
		return { mode: 'native', rendered: prepared.length, skipped }
	}

	_renderOverlay(document, renderer, prepared, skipped) {
		const leftOffset = Number.isFinite(renderer?.render?.leftPos)
			? renderer.render.leftPos
			: 0
		const topOffset = Number.isFinite(document.defaultView?.scrollY)
			? document.defaultView.scrollY
			: 0
		const rectangles = []
		const renderedIds = new Set()

		for (const { highlight, range } of prepared) {
			let rects
			try {
				rects = Array.from(range.getClientRects?.() ?? [])
			} catch {
				skipped += 1
				continue
			}
			for (const rect of rects) {
				if (!positiveRect(rect)) {
					continue
				}
				if (rectangles.length >= this.policy.highlightRectangles) {
					const error = new Error('EPUB highlights exceed the fallback rectangle limit.')
					safeCall(this.onError, error)
					return { mode: 'overlay', rendered: 0, skipped: skipped + prepared.length }
				}
				rectangles.push({ highlight, rect })
				renderedIds.add(highlight.id)
			}
		}

		if (rectangles.length === 0) {
			return { mode: 'overlay', rendered: 0, skipped: skipped + prepared.length }
		}

		const layer = document.createElement('div')
		layer.id = LAYER_ID
		layer.className = 'epubviewer-highlight-layer reader_generated'
		layer.style.pointerEvents = 'none'
		for (const { highlight, rect } of rectangles) {
			const marker = document.createElement('div')
			marker.className = `epubviewer-highlight-rect epubviewer-highlight-${highlight.color}`
			marker.dataset.highlightId = highlight.id
			marker.style.position = 'absolute'
			marker.style.left = `${rect.left + leftOffset}px`
			marker.style.top = `${rect.top + topOffset}px`
			marker.style.width = `${rect.width}px`
			marker.style.height = `${rect.height}px`
			layer.appendChild(marker)
		}
		document.body.appendChild(layer)
		this.overlay = layer
		return { mode: 'overlay', rendered: renderedIds.size, skipped }
	}

	_clearOutput() {
		const registry = this.currentWindow?.CSS?.highlights
		if (registry && typeof registry.delete === 'function') {
			for (const name of this.nativeNames) {
				registry.delete(name)
			}
		}
		this.nativeNames.clear()
		this.overlay?.remove?.()
		this.overlay = null
	}
}
