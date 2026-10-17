import {
	HIGHLIGHT_PALETTE,
	compareHighlights,
	excerptSelection,
	normalizeHighlight,
	rangeToHighlightAnchor,
	sameHighlightAnchor,
} from './model.js'
import { HighlightRepository } from './persistence.js'
import { EpubHighlightRenderer } from './renderer.js'

function safeCall(callback, ...args) {
	try {
		return callback(...args)
	} catch {
		return undefined
	}
}

function clamp(value, minimum, maximum) {
	return Math.min(Math.max(value, minimum), Math.max(minimum, maximum))
}

function promiseFailure(promise, callback) {
	if (promise && typeof promise.catch === 'function') {
		promise.catch(callback)
	}
}

function recordType(value) {
	return value && typeof value === 'object' ? value.type : null
}

function fallbackUuid() {
	if (typeof globalThis.crypto?.randomUUID === 'function') {
		return globalThis.crypto.randomUUID()
	}
	const random = Math.random().toString(36).slice(2)
	return `highlight-${Date.now().toString(36)}-${random}`
}

export function createHighlightsController({
	reader,
	book,
	document = globalThis.document,
	window = globalThis.window,
	EpubCFI = globalThis.EPUBJS?.EpubCFI,
	palette = HIGHLIGHT_PALETTE,
	policy = globalThis.ReaderLimits?.policy,
	now = Date.now,
	uuid = fallbackUuid,
	rangeToAnchor = rangeToHighlightAnchor,
	excerpt = excerptSelection,
	repositoryFactory = (options) => new HighlightRepository(options),
	rendererFactory = (options) => new EpubHighlightRenderer(options),
} = {}) {
	const settings = reader?.settings
	const session = settings?.session
	const annotations = settings?.annotations
	const toolbar = document?.getElementById?.('epub-highlight-toolbar')
	const view = document?.getElementById?.('highlightsView')
	const list = document?.getElementById?.('highlights')
	const status = document?.getElementById?.('highlight-status')
	if (!reader || !book?.renderer || !settings || !session
		|| !annotations || typeof annotations !== 'object'
		|| !toolbar || !view || !list || !status
		|| !EpubCFI || !policy || !window) {
		return {
			destroy() {},
			hide() {},
			show() {},
		}
	}

	let destroyed = false
	let pendingSelection = null
	let chapterDocument = null
	const toolbarListeners = []
	const eventListeners = []
	const bookListeners = []

	function setStatus(message, state = '') {
		status.textContent = message
		if (state === '') {
			delete status.dataset.state
		} else {
			status.dataset.state = state
		}
	}

	const highlightRenderer = rendererFactory({
		EpubCFI,
		palette,
		policy,
		onError(error) {
			setStatus(error instanceof Error ? error.message : 'Could not render EPUB highlights.', 'error')
		},
	})

	let repository
	function refreshRendering(activeRenderer = book.renderer) {
		if (destroyed || !activeRenderer) {
			return
		}
		const currentDocument = activeRenderer.doc ?? activeRenderer.render?.document
		const chapter = activeRenderer.currentChapter
		if (!currentDocument || !chapter) {
			return
		}
		highlightRenderer.schedule(currentDocument, chapter, activeRenderer, repository.list())
	}

	function handleStatus(event) {
		if (destroyed) {
			return
		}
		if (event.state === 'saving') {
			setStatus('Saving highlight…', 'saving')
		} else if (event.state === 'saved') {
			setStatus('Highlight saved.', 'saved')
		} else if (event.state === 'error') {
			const message = event.error instanceof Error
				? event.error.message
				: 'Could not save EPUB highlight.'
			setStatus(message, 'error')
		}
	}

	repository = repositoryFactory({
		annotations,
		session,
		policy,
		now,
		onChange() {
			if (destroyed) {
				return
			}
			renderSidebar()
			refreshRendering()
		},
		onStatus: handleStatus,
	})

	function hideToolbar() {
		pendingSelection = null
		toolbar.hidden = true
		toolbar.style.left = ''
		toolbar.style.top = ''
	}

	function positionToolbar(range) {
		const selectionRect = safeCall(() => range.getBoundingClientRect())
		if (!selectionRect || !Number.isFinite(selectionRect.left)
			|| !Number.isFinite(selectionRect.top)) {
			return false
		}
		const iframeRect = safeCall(() => book.renderer.render?.iframe?.getBoundingClientRect())
			?? { left: 0, top: 0 }
		toolbar.hidden = false
		const toolbarRect = safeCall(() => toolbar.getBoundingClientRect())
			?? { height: 0, width: 0 }
		const margin = 8
		let left = iframeRect.left + selectionRect.left
			+ (selectionRect.width ?? 0) / 2 - toolbarRect.width / 2
		let top = iframeRect.top + selectionRect.top - toolbarRect.height - margin
		if (top < margin) {
			top = iframeRect.top + (selectionRect.bottom ?? selectionRect.top) + margin
		}
		left = clamp(left, margin, window.innerWidth - toolbarRect.width - margin)
		top = clamp(top, margin, window.innerHeight - toolbarRect.height - margin)
		toolbar.style.left = `${Math.round(left)}px`
		toolbar.style.top = `${Math.round(top)}px`
		return true
	}

	function selectionRange(selection) {
		if (!selection || selection.rangeCount < 1 || selection.isCollapsed) {
			return null
		}
		const source = safeCall(() => selection.getRangeAt(0))
		if (!source || source.collapsed) {
			return null
		}
		const range = safeCall(() => source.cloneRange())
		const currentDocument = book.renderer.doc ?? book.renderer.render?.document
		if (!range || (range.startContainer?.ownerDocument
			&& currentDocument
			&& range.startContainer.ownerDocument !== currentDocument)) {
			return null
		}
		return range
	}

	function handleSelection(selection) {
		hideToolbar()
		if (destroyed) {
			return
		}
		const range = selectionRange(selection)
		const chapter = book.renderer.currentChapter
		if (!range || !chapter) {
			return
		}
		const anchor = safeCall(() => rangeToAnchor(range, chapter, EpubCFI, policy))
		const body = excerpt(String(selection.toString?.() ?? range.toString?.() ?? ''), policy.highlightTextCharacters)
		if (!anchor || body === '') {
			return
		}
		pendingSelection = { anchor, body, selection }
		if (!positionToolbar(range)) {
			pendingSelection = null
			toolbar.hidden = true
		}
	}

	function persistColor(color) {
		if (destroyed || !pendingSelection
			|| !Object.prototype.hasOwnProperty.call(palette, color)) {
			return
		}
		const captured = pendingSelection
		hideToolbar()
		safeCall(() => captured.selection.removeAllRanges())
		const existing = repository.list()
			.find((value) => sameHighlightAnchor(value.anchor, captured.anchor))
		if (existing) {
			promiseFailure(repository.update(existing.id, { color }), (error) => {
				setStatus(error instanceof Error ? error.message : 'Could not recolor EPUB highlight.', 'error')
			})
			return
		}
		const timestamp = now()
		const value = {
			id: String(uuid()),
			type: 'highlight',
			date: timestamp,
			edited: timestamp,
			anchor: captured.anchor,
			body: captured.body,
			color,
		}
		promiseFailure(repository.create(value), (error) => {
			setStatus(error instanceof Error ? error.message : 'Could not save EPUB highlight.', 'error')
		})
	}

	function createButton(label, className) {
		const button = document.createElement('button')
		button.setAttribute('type', 'button')
		button.setAttribute('aria-label', label)
		button.classList.add(className)
		return button
	}

	function renderHighlightItem(highlight) {
		const item = document.createElement('li')
		item.classList.add('epub-highlight-item')
		item.dataset.highlightId = highlight.id

		const link = createButton('Go to highlighted text', 'epub-highlight-link')
		link.textContent = highlight.body || 'Highlighted text'
		link.addEventListener('click', () => {
			hideToolbar()
			safeCall(() => book.gotoCfi(highlight.anchor.start))
		})

		const controls = document.createElement('div')
		controls.classList.add('epub-highlight-controls')
		const select = document.createElement('select')
		select.classList.add('epub-highlight-color')
		select.setAttribute('aria-label', 'Highlight color')
		for (const color of Object.keys(palette)) {
			const option = document.createElement('option')
			option.value = color
			option.textContent = color[0].toUpperCase() + color.slice(1)
			select.appendChild(option)
		}
		select.value = highlight.color
		select.addEventListener('change', () => {
			promiseFailure(repository.update(highlight.id, { color: select.value }), (error) => {
				setStatus(error instanceof Error ? error.message : 'Could not recolor EPUB highlight.', 'error')
			})
		})

		const remove = createButton('Delete highlight', 'epub-highlight-delete')
		remove.textContent = 'Delete'
		remove.addEventListener('click', () => {
			promiseFailure(repository.remove(highlight.id), (error) => {
				setStatus(error instanceof Error ? error.message : 'Could not delete EPUB highlight.', 'error')
			})
		})

		controls.append(select, remove)
		item.append(link, controls)
		return item
	}

	function renderSidebar() {
		if (destroyed) {
			return
		}
		const fragment = []
		const highlights = repository.list()
			.sort((left, right) => compareHighlights(left, right, EpubCFI))
		for (const highlight of highlights) {
			fragment.push(renderHighlightItem(highlight))
		}
		for (const value of Object.values(annotations)) {
			if (recordType(value) === 'highlight' && normalizeHighlight(value, policy) === null) {
				const item = document.createElement('li')
				item.classList.add('epub-highlight-item', 'epub-highlight-unavailable')
				item.textContent = 'Unavailable saved highlight'
				fragment.push(item)
			}
		}
		list.replaceChildren(...fragment)
		if (fragment.length === 0 && status.dataset.state !== 'error') {
			setStatus('No highlights yet.')
		}
	}

	function bindChapterDocument(nextDocument) {
		if (chapterDocument === nextDocument) {
			return
		}
		if (chapterDocument) {
			chapterDocument.removeEventListener?.('pointerdown', handleChapterPointerDown)
			chapterDocument.removeEventListener?.('keydown', handleKeyDown)
		}
		chapterDocument = nextDocument
		chapterDocument?.addEventListener?.('pointerdown', handleChapterPointerDown)
		chapterDocument?.addEventListener?.('keydown', handleKeyDown)
	}

	function handleChapterPointerDown() {
		hideToolbar()
	}

	function handleOuterPointerDown(event) {
		if (!toolbar.hidden && !toolbar.contains(event.target)) {
			hideToolbar()
		}
	}

	function handleKeyDown(event) {
		if (event.key === 'Escape') {
			hideToolbar()
		}
	}

	function handleLocationChange() {
		hideToolbar()
		refreshRendering()
	}

	function handleResize() {
		hideToolbar()
		refreshRendering()
	}

	const chapterHook = (complete, activeRenderer) => {
		hideToolbar()
		bindChapterDocument(activeRenderer?.doc ?? activeRenderer?.render?.document ?? null)
		refreshRendering(activeRenderer)
		safeCall(complete)
	}

	function addBookListener(name, listener) {
		if (typeof book.on === 'function') {
			book.on(name, listener)
			bookListeners.push([name, listener])
		}
	}

	function addEventListener(target, name, listener) {
		if (typeof target?.addEventListener === 'function') {
			target.addEventListener(name, listener)
			eventListeners.push([target, name, listener])
		}
	}

	for (const button of toolbar.querySelectorAll('[data-highlight-color]')) {
		const listener = () => persistColor(button.dataset.highlightColor)
		button.addEventListener('click', listener)
		toolbarListeners.push([button, listener])
	}
	addEventListener(document, 'keydown', handleKeyDown)
	addEventListener(document, 'pointerdown', handleOuterPointerDown)
	addEventListener(window, 'pagehide', destroy)
	addBookListener('renderer:selected', handleSelection)
	addBookListener('renderer:locationChanged', handleLocationChange)
	addBookListener('renderer:resized', handleResize)
	book.renderer.registerHook?.('beforeChapterDisplay', chapterHook, true)
	bindChapterDocument(book.renderer.doc ?? book.renderer.render?.document ?? null)

	function show() {
		view.classList.add('open')
		renderSidebar()
	}

	function hide() {
		view.classList.remove('open')
	}

	function destroy() {
		if (destroyed) {
			return
		}
		destroyed = true
		hideToolbar()
		for (const [button, listener] of toolbarListeners) {
			button.removeEventListener('click', listener)
		}
		for (const [target, name, listener] of eventListeners) {
			target.removeEventListener(name, listener)
		}
		for (const [name, listener] of bookListeners) {
			book.off?.(name, listener)
		}
		bindChapterDocument(null)
		book.renderer.removeHook?.('beforeChapterDisplay', chapterHook)
		highlightRenderer.clear()
	}

	renderSidebar()
	refreshRendering()

	return {
		destroy,
		hide,
		refresh: refreshRendering,
		show,
	}
}
