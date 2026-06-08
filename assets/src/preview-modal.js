/**
 * <PreviewModal> — shown after a preview request resolves.
 *
 * Three jobs:
 *
 *   1. Surface the counts (total / importable / skipped) so the user
 *      knows what they're about to commit to.
 *   2. Show a handful of sample rows so they can sanity-check the
 *      mapping before pulling the trigger.
 *   3. Drive the chunked /imports/run loop, updating a progress bar
 *      as batches return. Errors stream in alongside the bar so the
 *      user sees skipped rows in real time, not just at the end.
 *
 * The modal owns its own progress state — when the parent calls
 * `onClose()` mid-import we set an `abortedRef` so the in-flight loop
 * stops on the next iteration without leaving a stale `running` flag.
 */
import { __, sprintf, _n } from '@wordpress/i18n'
import { useEffect, useRef, useState } from '@wordpress/element'
import {
	Button,
	Modal,
	Notice,
	ToggleControl,
} from '@wordpress/components'

import { cleanupCsv, runImportBatch } from './api'

// How many rows to process per /imports/run call. Larger batches are
// faster (fewer round-trips) but make the progress bar feel laggy on
// small imports; 100 is a comfortable balance for both.
const BATCH_SIZE = 100

const PreviewModal = ({ preview, sourceId, csvToken, onClose, onDone }) => {
	const [updateExisting, setUpdateExisting] = useState(false)
	const [running, setRunning] = useState(false)
	const [progress, setProgress] = useState({
		processed: 0,
		created: 0,
		updated: 0,
		skipped: 0,
		errors: [],
	})
	const [doneSummary, setDoneSummary] = useState(null)
	const [runError, setRunError] = useState(null)
	const abortedRef = useRef(false)

	// `total` from the preview is the source's reported row count.
	// We compare `processed` against it to drive the percentage bar
	// and decide when to stop the loop. Falling back to `importable`
	// covers the edge case where the source can't predict its own
	// count cheaply.
	const total = preview?.total || preview?.importable || 0

	useEffect(() => {
		// Resetting on each open keeps stale state out of subsequent
		// previews (eg. picked a different source after dismissing).
		abortedRef.current = false
		setRunning(false)
		setProgress({
			processed: 0,
			created: 0,
			updated: 0,
			skipped: 0,
			errors: [],
		})
		setDoneSummary(null)
		setRunError(null)
	}, [preview])

	const handleClose = async () => {
		abortedRef.current = true
		if (csvToken) {
			await cleanupCsv(csvToken)
		}
		onClose()
	}

	const startImport = async () => {
		abortedRef.current = false
		setRunning(true)
		setRunError(null)

		let offset = 0
		let totals = {
			processed: 0,
			created: 0,
			updated: 0,
			skipped: 0,
			errors: [],
		}

		try {
			// Loop until the server says we've drained the source. Each
			// response carries `next_offset` + a `done` flag so the
			// client doesn't have to track much of its own state.
			// eslint-disable-next-line no-constant-condition
			while (true) {
				if (abortedRef.current) {
					break
				}

				// eslint-disable-next-line no-await-in-loop
				const batch = await runImportBatch({
					sourceId,
					csvToken,
					offset,
					limit: BATCH_SIZE,
					updateExisting,
				})

				totals = {
					processed: totals.processed + (batch.processed || 0),
					created: totals.created + (batch.created || 0),
					updated: totals.updated + (batch.updated || 0),
					skipped: totals.skipped + (batch.skipped || 0),
					// Cap the error list at 500 entries — a runaway import
					// could otherwise produce a multi-megabyte payload
					// when re-rendered. The full list is still on the
					// server's response history; we just stop accreting
					// in memory.
					errors: [
						...totals.errors,
						...(Array.isArray(batch.errors) ? batch.errors : []),
					].slice(0, 500),
				}

				setProgress({ ...totals })

				offset = batch.next_offset ?? offset + BATCH_SIZE

				if (batch.done) {
					break
				}
			}

			if (!abortedRef.current) {
				setDoneSummary(totals)
				onDone?.(totals)
			}
		} catch (e) {
			setRunError(
				e?.message ||
					__(
						'Import failed mid-batch.',
						'404-to-301-redirects-importer',
					),
			)
		} finally {
			setRunning(false)
		}
	}

	const percent =
		total > 0
			? Math.min(100, Math.round((progress.processed / total) * 100))
			: 0

	return (
		<Modal
			title={__('Review import', '404-to-301-redirects-importer')}
			onRequestClose={handleClose}
			shouldCloseOnClickOutside={!running}
			shouldCloseOnEsc={!running}
			className="d404-importer-modal"
			size="large"
		>
			{!doneSummary && (
				<>
					<div className="d404-importer-modal__counts">
						<Stat
							label={__(
								'Total',
								'404-to-301-redirects-importer',
							)}
							value={preview?.total ?? 0}
						/>
						<Stat
							label={__(
								'Importable',
								'404-to-301-redirects-importer',
							)}
							value={preview?.importable ?? 0}
							highlight
						/>
						<Stat
							label={__(
								'Skipped',
								'404-to-301-redirects-importer',
							)}
							value={preview?.skipped ?? 0}
						/>
					</div>

					{preview?.sample?.length > 0 && (
						<details
							className="d404-importer-modal__sample"
							open
						>
							<summary>
								{__(
									'Sample of mapped rows',
									'404-to-301-redirects-importer',
								)}
							</summary>
							<table>
								<thead>
									<tr>
										<th>
											{__(
												'Source',
												'404-to-301-redirects-importer',
											)}
										</th>
										<th>
											{__(
												'Target',
												'404-to-301-redirects-importer',
											)}
										</th>
										<th>
											{__(
												'Match',
												'404-to-301-redirects-importer',
											)}
										</th>
										<th>
											{__(
												'Code',
												'404-to-301-redirects-importer',
											)}
										</th>
									</tr>
								</thead>
								<tbody>
									{preview.sample.map((row, i) => (
										<tr key={i}>
											<td>{row.source}</td>
											<td>{row.target_url || '—'}</td>
											<td>{row.match_type}</td>
											<td>{row.redirect_type}</td>
										</tr>
									))}
								</tbody>
							</table>
						</details>
					)}

					{preview?.errors?.length > 0 && (
						<SkipDetails errors={preview.errors} />
					)}

					<ToggleControl
						__nextHasNoMarginBottom
						label={__(
							'Update existing redirects',
							'404-to-301-redirects-importer',
						)}
						help={__(
							'When a row matches an existing redirect (same source), overwrite its values instead of skipping the row.',
							'404-to-301-redirects-importer',
						)}
						checked={updateExisting}
						onChange={setUpdateExisting}
						disabled={running}
					/>
				</>
			)}

			{running && (
				<div
					className="d404-importer-modal__progress"
					role="status"
					aria-live="polite"
				>
					<div className="d404-importer-modal__bar">
						<div
							className="d404-importer-modal__bar-fill"
							style={{ width: `${percent}%` }}
						/>
					</div>
					<p>
						{sprintf(
							/* translators: 1: processed rows, 2: total rows, 3: percent. */
							__(
								'Imported %1$d of %2$d rows (%3$d%%)',
								'404-to-301-redirects-importer',
							),
							progress.processed,
							total,
							percent,
						)}
					</p>
				</div>
			)}

			{doneSummary && (
				<Notice
					status={
						doneSummary.created || doneSummary.updated
							? 'success'
							: 'warning'
					}
					isDismissible={false}
				>
					{sprintf(
						/* translators: 1: created count, 2: updated count, 3: skipped count. */
						__(
							'Done — %1$d created, %2$d updated, %3$d skipped.',
							'404-to-301-redirects-importer',
						),
						doneSummary.created,
						doneSummary.updated,
						doneSummary.skipped,
					)}
				</Notice>
			)}

			{runError && (
				<Notice status="error" isDismissible={false}>
					{runError}
				</Notice>
			)}

			{(doneSummary?.errors?.length > 0 ||
				progress.errors.length > 0) && (
				<SkipDetails
					errors={doneSummary?.errors || progress.errors}
				/>
			)}

			<div className="d404-importer-modal__actions">
				{!doneSummary && (
					<Button
						variant="primary"
						onClick={startImport}
						isBusy={running}
						disabled={running || preview?.importable === 0}
					>
						{running
							? __(
									'Importing…',
									'404-to-301-redirects-importer',
								)
							: __(
									'Start import',
									'404-to-301-redirects-importer',
								)}
					</Button>
				)}
				<Button
					variant={doneSummary ? 'primary' : 'tertiary'}
					onClick={handleClose}
					disabled={running}
				>
					{doneSummary
						? __('Close', '404-to-301-redirects-importer')
						: __('Cancel', '404-to-301-redirects-importer')}
				</Button>
			</div>
		</Modal>
	)
}

const Stat = ({ label, value, highlight = false }) => (
	<div
		className={`d404-importer-stat${highlight ? ' is-highlight' : ''}`}
	>
		<span className="d404-importer-stat__value">{value}</span>
		<span className="d404-importer-stat__label">{label}</span>
	</div>
)

const SkipDetails = ({ errors }) => (
	<details className="d404-importer-modal__errors">
		<summary>
			{sprintf(
				/* translators: %d: row count. */
				_n(
					'%d row needs attention',
					'%d rows need attention',
					errors.length,
					'404-to-301-redirects-importer',
				),
				errors.length,
			)}
		</summary>
		<ul>
			{errors.slice(0, 100).map((err, idx) => (
				<li key={`${err.row}-${idx}`}>
					{sprintf(
						/* translators: 1: row id/number, 2: error message. */
						__(
							'Row %1$d: %2$s',
							'404-to-301-redirects-importer',
						),
						err.row,
						err.message,
					)}
				</li>
			))}
			{errors.length > 100 && (
				<li>
					{sprintf(
						/* translators: %d: number of additional skipped rows. */
						__(
							'…and %d more',
							'404-to-301-redirects-importer',
						),
						errors.length - 100,
					)}
				</li>
			)}
		</ul>
	</details>
)

export default PreviewModal
