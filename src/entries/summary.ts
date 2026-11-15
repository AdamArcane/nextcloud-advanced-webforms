/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// What the responses add up to (docs/SCHEMA.md section 17.2).

import { t } from '@nextcloud/l10n'

export interface TimelineBucket {
	/** `YYYY-MM-DD` in UTC: the day, or the first day of the month. */
	start: string
	count: number
}

interface SummaryFieldBase {
	id: string
	label: string
	type: string
	/** How many responses answered this question. */
	answered: number
}

export interface ChoiceSummary extends SummaryFieldBase {
	kind: 'choice'
	options: { value: string, label: string, count: number }[]
}

export interface NumberSummary extends SummaryFieldBase {
	kind: 'number'
	min: number | null
	max: number | null
	average: number | null
	sum: number | null
}

export interface YesNoSummary extends SummaryFieldBase {
	kind: 'yesno'
	yes: number
	no: number
}

export type SummaryField = ChoiceSummary | NumberSummary | YesNoSummary

export interface EntrySummaryData {
	total: number
	/** True when the search stopped early, so the numbers cover only the first 20 000 responses. */
	truncated: boolean
	timeline: { unit: 'day' | 'month', buckets: TimelineBucket[] }
	fields: SummaryField[]
}

export interface BarRow {
	key: string
	label: string
	count: number
	/** Share of the people who answered the question, 0 to 100, whole number. */
	percent: number
	/** Bar length relative to the longest bar in the group, 0 to 100. */
	width: number
}

/** The rows of a bar group for a choice or yes/no question, in the form's own order. */
export function barRows(field: ChoiceSummary | YesNoSummary): BarRow[] {
	const items = field.kind === 'choice'
		? field.options.map((option) => ({ key: option.value, label: option.label, count: option.count }))
		: [
			{ key: 'yes', label: t('webforms', 'Yes'), count: field.yes },
			{ key: 'no', label: t('webforms', 'No'), count: field.no },
		]
	const longest = Math.max(1, ...items.map((item) => item.count))
	return items.map((item) => ({
		...item,
		percent: field.answered > 0 ? Math.round((item.count / field.answered) * 100) : 0,
		width: (item.count / longest) * 100,
	}))
}

/** A bucket's date as people read it: a day, or a month. Dates are UTC, like the filters. */
export function bucketLabel(bucket: TimelineBucket, unit: 'day' | 'month', locale?: string): string {
	const date = new Date(bucket.start + 'T00:00:00Z')
	return unit === 'month'
		? date.toLocaleDateString(locale, { timeZone: 'UTC', month: 'long', year: 'numeric' })
		: date.toLocaleDateString(locale, { timeZone: 'UTC', month: 'short', day: 'numeric', year: 'numeric' })
}

/** Column heights for the timeline, 0 to 100. A day with any responses is never drawn as nothing. */
export function columnHeights(buckets: TimelineBucket[]): number[] {
	const tallest = Math.max(1, ...buckets.map((bucket) => bucket.count))
	return buckets.map((bucket) => (bucket.count === 0 ? 0 : Math.max(4, (bucket.count / tallest) * 100)))
}

/** A number as the summary shows it: at most two decimals, no trailing zeros. */
export function formatNumber(value: number | null, locale?: string): string {
	return value === null ? '—' : value.toLocaleString(locale, { maximumFractionDigits: 2 })
}
