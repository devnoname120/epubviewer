import { normalizeHighlight } from './model.js'

function requestPromise(request) {
	if (!request || typeof request.then !== 'function') {
		return Promise.resolve(request)
	}
	return new Promise((resolve, reject) => {
		request.then(resolve, reject)
	})
}

function operationError(error, fallback) {
	return error instanceof Error ? error : new Error(fallback)
}

export class HighlightRepository {
	constructor({
		annotations,
		session,
		policy = globalThis.ReaderLimits?.policy,
		now = Date.now,
		onChange = () => {},
		onStatus = () => {},
	}) {
		if (!annotations || typeof annotations !== 'object' || !session
			|| typeof session.setBookmark !== 'function'
			|| typeof session.deleteBookmark !== 'function'
			|| !policy || typeof policy !== 'object') {
			throw new TypeError('EPUB highlight persistence is unavailable.')
		}
		this.annotations = annotations
		this.session = session
		this.policy = policy
		this.now = now
		this.onChange = onChange
		this.onStatus = onStatus
		this.pending = new Map()
	}

	list() {
		const highlights = []
		for (const value of Object.values(this.annotations)) {
			const normalized = normalizeHighlight(value, this.policy)
			if (normalized !== null) {
				highlights.push(normalized)
			}
		}
		return highlights
	}

	create(value) {
		const normalized = normalizeHighlight(value, this.policy)
		if (normalized === null) {
			return Promise.reject(new Error('Highlight data is invalid or exceeds storage limits.'))
		}
		return this._enqueue(normalized.id, async () => {
			if (this.annotations[normalized.id] !== undefined) {
				throw new Error('Highlight identifier already exists.')
			}
			if (this.list().length >= this.policy.highlights) {
				throw new Error('EPUB highlight limit reached.')
			}

			this.annotations[normalized.id] = normalized
			this._change('create', normalized.id, normalized)
			this._status('create', normalized.id, 'saving')
			try {
				await requestPromise(this.session.setBookmark(
					normalized.id,
					normalized.anchor.start,
					'highlight',
					normalized,
				))
				this._status('create', normalized.id, 'saved')
				return normalizeHighlight(normalized, this.policy)
			} catch (error) {
				delete this.annotations[normalized.id]
				this._change('rollback-create', normalized.id, null)
				const failure = operationError(error, 'Could not save EPUB highlight.')
				this._status('create', normalized.id, 'error', failure)
				throw failure
			}
		})
	}

	update(id, changes) {
		return this._enqueue(id, async () => {
			const previous = normalizeHighlight(this.annotations[id], this.policy)
			if (previous === null) {
				throw new Error('Highlight does not exist or is invalid.')
			}
			const candidate = normalizeHighlight({
				...previous,
				...(Object.prototype.hasOwnProperty.call(changes ?? {}, 'body') ? { body: changes.body } : {}),
				...(Object.prototype.hasOwnProperty.call(changes ?? {}, 'color') ? { color: changes.color } : {}),
				...(Object.prototype.hasOwnProperty.call(changes ?? {}, 'anchor')
					? { anchor: { ...previous.anchor, ...changes.anchor } }
					: {}),
				edited: this.now(),
			}, this.policy)
			if (candidate === null) {
				throw new Error('Updated highlight data is invalid or exceeds storage limits.')
			}

			this.annotations[id] = candidate
			this._change('update', id, candidate)
			this._status('update', id, 'saving')
			try {
				await requestPromise(this.session.setBookmark(
					candidate.id,
					candidate.anchor.start,
					'highlight',
					candidate,
				))
				this._status('update', id, 'saved')
				return normalizeHighlight(candidate, this.policy)
			} catch (error) {
				this.annotations[id] = previous
				this._change('rollback-update', id, previous)
				const failure = operationError(error, 'Could not update EPUB highlight.')
				this._status('update', id, 'error', failure)
				throw failure
			}
		})
	}

	remove(id) {
		return this._enqueue(id, async () => {
			const previous = normalizeHighlight(this.annotations[id], this.policy)
			if (previous === null) {
				return false
			}

			delete this.annotations[id]
			this._change('delete', id, null)
			this._status('delete', id, 'saving')
			try {
				await requestPromise(this.session.deleteBookmark(id))
				this._status('delete', id, 'saved')
				return true
			} catch (error) {
				this.annotations[id] = previous
				this._change('rollback-delete', id, previous)
				const failure = operationError(error, 'Could not delete EPUB highlight.')
				this._status('delete', id, 'error', failure)
				throw failure
			}
		})
	}

	_enqueue(id, operation) {
		const previous = this.pending.get(id) ?? Promise.resolve()
		const current = previous.then(operation, operation)
		let tail
		tail = current.then(
			() => undefined,
			() => undefined,
		).finally(() => {
			if (this.pending.get(id) === tail) {
				this.pending.delete(id)
			}
		})
		this.pending.set(id, tail)
		return current
	}

	_change(action, id, value) {
		try {
			this.onChange({
				action,
				id,
				highlight: value === null ? null : normalizeHighlight(value, this.policy),
			})
		} catch {
			// Persistence must not depend on optional UI observers succeeding.
		}
	}

	_status(operation, id, state, error = null) {
		try {
			this.onStatus({ error, id, operation, state })
		} catch {
			// Status rendering is best-effort and cannot invalidate a server write.
		}
	}
}
