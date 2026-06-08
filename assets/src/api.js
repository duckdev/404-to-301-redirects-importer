/**
 * Thin REST helpers for the importer.
 *
 * Wraps `@wordpress/api-fetch` for the JSON endpoints and falls back
 * to plain `fetch` for the multipart upload (api-fetch JSON-encodes
 * everything it sees, which mangles a `FormData` body).
 *
 * All four endpoints live under the parent's `/404-to-301/v1`
 * namespace so the WP nonce that api-fetch attaches is already
 * accepted server-side — no client-side plumbing needed.
 */
import apiFetch from '@wordpress/api-fetch'

const PATH = '/404-to-301/v1/imports'

/** GET /imports/sources → `[{ id, label, count }]` */
export const listSources = () => apiFetch({ path: `${PATH}/sources` })

/**
 * POST /imports/upload — multipart file upload.
 *
 * Returns `{ csv_token, filename, count }`. `count` is the data-row
 * count detected in the stashed file, so the UI can show a "found N
 * rows" hint before the preview comes back.
 */
export const uploadCsv = async (file) => {
	const body = new FormData()
	body.append('file', file)

	const root = window.wpApiSettings?.root || '/wp-json/'
	const nonce = window.wpApiSettings?.nonce || ''

	const response = await fetch(`${root}404-to-301/v1/imports/upload`, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': nonce },
		body,
	})

	const payload = await response.json().catch(() => ({}))

	if (!response.ok) {
		const err = new Error(payload?.message || 'Upload failed')
		err.payload = payload
		throw err
	}

	return payload
}

/** POST /imports/preview — dry-run summary for the modal. */
export const previewImport = ({ sourceId, csvToken }) =>
	apiFetch({
		path: `${PATH}/preview`,
		method: 'POST',
		data: { source_id: sourceId, csv_token: csvToken || '' },
	})

/** POST /imports/run — process one offset/limit batch. */
export const runImportBatch = ({
	sourceId,
	csvToken,
	offset,
	limit,
	updateExisting,
}) =>
	apiFetch({
		path: `${PATH}/run`,
		method: 'POST',
		data: {
			source_id: sourceId,
			csv_token: csvToken || '',
			offset,
			limit,
			update_existing: !!updateExisting,
		},
	})

/** POST /imports/cleanup — drop a CSV token's tmp file. */
export const cleanupCsv = (csvToken) =>
	apiFetch({
		path: `${PATH}/cleanup`,
		method: 'POST',
		data: { csv_token: csvToken },
	}).catch(() => {
		// Best-effort tidy — a failed cleanup just leaves the file
		// for the transient TTL to garbage-collect. Don't surface
		// the error to the user.
	})
