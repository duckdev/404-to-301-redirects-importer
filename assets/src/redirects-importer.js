/**
 * 404 to 301 — Redirects Importer: entry point.
 *
 * Loaded by the addon on the parent plugin's Settings screen. Registers
 * a single `addFilter` callback on `d404.settings.tools.fields` that
 * mounts the bulk-import panel at the end of the Tools tab.
 *
 * The filter runs on the same screen the addon is already enqueued on,
 * so we don't need a separate suppression bundle.
 */
import { addFilter } from '@wordpress/hooks'

import ImportPanel from './import-panel'
import './redirects-importer.scss'

addFilter(
	'd404.settings.tools.fields',
	'redirects-importer/import-panel',
	(existing) => (
		<>
			{existing}
			<ImportPanel key="redirects-importer-panel" />
		</>
	),
)
