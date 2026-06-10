/**
 * <ImportPanel> — bulk-import panel injected into the Tools tab.
 *
 * Two input modes, selected via radio:
 *
 *   1. "CSV upload" — pick a file, upload it, then preview.
 *   2. "Another plugin" — pick a detected source from the dropdown,
 *      then preview.
 *
 * Both paths converge on the same <PreviewModal>, which owns the
 * actual import loop + progress bar. Picking a source is intentionally
 * a separate step from running the import — the preview gives the
 * user a sanity-check before committing to writes.
 */
import { __, sprintf } from '@wordpress/i18n'
import { useEffect, useRef, useState } from '@wordpress/element'
import {
	Button,
	Notice,
	PanelBody,
	PanelRow,
	RadioControl,
	SelectControl,
	Spinner,
} from '@wordpress/components'

import { listSources, previewImport, uploadCsv } from './api'
import PreviewModal from './preview-modal'

const ImportPanel = () => {
	const [mode, setMode] = useState('csv')
	const [sources, setSources] = useState([])
	const [sourcesLoading, setSourcesLoading] = useState(true)
	const [pluginSourceId, setPluginSourceId] = useState('')
	const [csvToken, setCsvToken] = useState('')
	const [csvFilename, setCsvFilename] = useState('')
	const [csvDetectedCount, setCsvDetectedCount] = useState(0)
	const [busy, setBusy] = useState(false)
	const [notice, setNotice] = useState(null)
	const [preview, setPreview] = useState(null)
	const fileInputRef = useRef(null)

	// Load the available sources once. CSV is always there; plugin
	// sources only show up when their data store is detected — so an
	// empty list means "no third-party plugins to import from" rather
	// than "API broken".
	useEffect(() => {
		let cancelled = false

		listSources()
			.then((res) => {
				if (cancelled) return
				setSources(Array.isArray(res?.items) ? res.items : [])
			})
			.catch(() => {
				if (cancelled) return
				setSources([])
			})
			.finally(() => {
				if (cancelled) return
				setSourcesLoading(false)
			})

		return () => {
			cancelled = true
		}
	}, [])

	// Plugin sources are everything except CSV — the CSV source is
	// always registered but doesn't make sense as a row in the
	// dropdown (it's the "other" radio option).
	const pluginSources = sources.filter((s) => s.id !== 'csv')

	// Auto-select the first plugin source so the dropdown isn't an
	// empty-looking control when the user flips to the plugin tab.
	useEffect(() => {
		if (mode === 'plugin' && !pluginSourceId && pluginSources[0]) {
			setPluginSourceId(pluginSources[0].id)
		}
	}, [mode, pluginSourceId, pluginSources])

	const resetCsv = () => {
		setCsvToken('')
		setCsvFilename('')
		setCsvDetectedCount(0)
		if (fileInputRef.current) {
			fileInputRef.current.value = ''
		}
	}

	const onFilePicked = async (event) => {
		const file = event.target.files?.[0]
		if (!file) return

		setNotice(null)
		setBusy(true)
		resetCsv()

		try {
			const res = await uploadCsv(file)
			setCsvToken(res.csv_token || '')
			setCsvFilename(res.filename || file.name)
			setCsvDetectedCount(res.count || 0)
		} catch (e) {
			setNotice({
				status: 'error',
				message:
					e?.message ||
					__(
						'Could not upload the CSV.',
						'404-to-301-redirects-importer',
					),
			})
		} finally {
			setBusy(false)
		}
	}

	const onPreview = async () => {
		setNotice(null)
		setBusy(true)

		try {
			const sourceId = mode === 'csv' ? 'csv' : pluginSourceId
			const res = await previewImport({
				sourceId,
				csvToken: mode === 'csv' ? csvToken : '',
			})

			if (res.total === 0 && res.importable === 0) {
				setNotice({
					status: 'warning',
					message: __(
						'There are no importable rows in this source.',
						'404-to-301-redirects-importer',
					),
				})
				return
			}

			setPreview({ ...res, _sourceId: sourceId })
		} catch (e) {
			setNotice({
				status: 'error',
				message:
					e?.message ||
					__(
						'Preview failed.',
						'404-to-301-redirects-importer',
					),
			})
		} finally {
			setBusy(false)
		}
	}

	const previewDisabled =
		busy ||
		(mode === 'csv' && !csvToken) ||
		(mode === 'plugin' && (!pluginSourceId || pluginSources.length === 0))

	return (
		<>
			<PanelBody
				title={__(
					'Import Redirects',
					'404-to-301-redirects-importer',
				)}
				initialOpen={true}
			>
				<PanelRow>
					<div className="d404-redirects-importer">
						<RadioControl
							label={__(
								'Import from',
								'404-to-301-redirects-importer',
							)}
							selected={mode}
							onChange={setMode}
							options={[
								{
									label: __(
										'CSV file',
										'404-to-301-redirects-importer',
									),
									value: 'csv',
								},
								{
									label: __(
										'Another plugin',
										'404-to-301-redirects-importer',
									),
									value: 'plugin',
								},
							]}
						/>

						{mode === 'csv' && (
							<div className="d404-redirects-importer__csv">
								<p className="components-base-control__help">
									{__(
										'Required column: `source`. Optional columns: `target_url`, `match_type`, `target_type`, `target_page_id`, `redirect_type`, `is_active`, `query_handling`, `notes`.',
										'404-to-301-redirects-importer',
									)}
								</p>
								<input
									ref={fileInputRef}
									type="file"
									accept=".csv,text/csv,text/plain"
									onChange={onFilePicked}
									disabled={busy}
								/>
								{csvFilename && (
									<p className="d404-redirects-importer__filename">
										{sprintf(
											/* translators: 1: filename, 2: detected row count. */
											__(
												'%1$s — %2$d rows detected',
												'404-to-301-redirects-importer',
											),
											csvFilename,
											csvDetectedCount,
										)}
									</p>
								)}
							</div>
						)}

						{mode === 'plugin' && (
							<div className="d404-redirects-importer__plugin">
								{sourcesLoading && <Spinner />}
								{!sourcesLoading &&
									pluginSources.length === 0 && (
										<Notice
											status="info"
											isDismissible={false}
										>
											{__(
												"We didn't detect any supported redirect plugins on this site. Currently supported: Redirection, 301 Redirects (Redirect Manager).",
												'404-to-301-redirects-importer',
											)}
										</Notice>
									)}
								{!sourcesLoading && pluginSources.length > 0 && (
									<SelectControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										label={__(
											'Source plugin',
											'404-to-301-redirects-importer',
										)}
										value={pluginSourceId}
										onChange={setPluginSourceId}
										options={pluginSources.map((s) => ({
											value: s.id,
											label: sprintf(
												/* translators: 1: plugin label, 2: row count. */
												__(
													'%1$s — %2$d rows',
													'404-to-301-redirects-importer',
												),
												s.label,
												s.count,
											),
										}))}
									/>
								)}
							</div>
						)}

						<div className="d404-redirects-importer__actions">
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={onPreview}
								isBusy={busy}
								disabled={previewDisabled}
							>
								{busy
									? __(
											'Working…',
											'404-to-301-redirects-importer',
										)
									: __(
											'Preview import',
											'404-to-301-redirects-importer',
										)}
							</Button>
							{mode === 'csv' && csvFilename && !busy && (
								<Button
									variant="tertiary"
									onClick={resetCsv}
								>
									{__(
										'Reset',
										'404-to-301-redirects-importer',
									)}
								</Button>
							)}
						</div>

						{notice && (
							<Notice
								status={notice.status}
								isDismissible
								onRemove={() => setNotice(null)}
							>
								{notice.message}
							</Notice>
						)}
					</div>
				</PanelRow>
			</PanelBody>

			{preview && (
				<PreviewModal
					preview={preview}
					sourceId={preview._sourceId}
					csvToken={mode === 'csv' ? csvToken : ''}
					onClose={() => {
						setPreview(null)
						if (mode === 'csv') {
							resetCsv()
						}
					}}
					onDone={() => {
						// Successful imports invalidate the parent's
						// cached Redirects list — but we don't have a
						// store handle from here. Easiest signal: tell
						// the user to refresh the Redirects page;
						// future work could dispatch a custom event the
						// parent listens for.
					}}
				/>
			)}
		</>
	)
}

export default ImportPanel
