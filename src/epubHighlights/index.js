import { createHighlightsController } from './controller.js'

export function registerEpubHighlights(EPUBJS = globalThis.EPUBJS) {
	if (!EPUBJS?.reader?.plugins || typeof EPUBJS.EpubCFI !== 'function') {
		return false
	}
	if (typeof EPUBJS.reader.plugins.HighlightsController === 'function') {
		return true
	}

	EPUBJS.reader.plugins.HighlightsController = function (book) {
		return createHighlightsController({
			EpubCFI: EPUBJS.EpubCFI,
			book,
			document: globalThis.document,
			reader: this,
			uuid: typeof EPUBJS.core?.uuid === 'function'
				? EPUBJS.core.uuid.bind(EPUBJS.core)
				: undefined,
			window: globalThis.window,
		})
	}
	return true
}

registerEpubHighlights()
