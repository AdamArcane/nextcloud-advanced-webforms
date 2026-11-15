/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import qrcode from 'qrcode-generator'

/** Quiet zone around the code, in modules. The standard asks for four. */
const MARGIN = 4

/** The QR code for `text` as rows of dark (true) and light (false) modules. Made in the browser, nothing is sent anywhere. */
export function qrModules(text: string): boolean[][] {
	// Medium error correction: a printed code survives a crease or a smudge without growing much.
	const code = qrcode(0, 'M')
	code.addData(text, 'Byte')
	code.make()
	const size = code.getModuleCount()
	const rows: boolean[][] = []
	for (let row = 0; row < size; row++) {
		const cells: boolean[] = []
		for (let column = 0; column < size; column++) {
			cells.push(code.isDark(row, column))
		}
		rows.push(cells)
	}
	return rows
}

/** A standalone SVG of the code: black on white with its quiet zone, so it scans on any background. */
export function qrSvg(text: string): string {
	const rows = qrModules(text)
	const size = rows.length + MARGIN * 2
	let path = ''
	rows.forEach((cells, row) => {
		// One run per stretch of dark modules keeps the file small.
		let start = -1
		for (let column = 0; column <= cells.length; column++) {
			const dark = column < cells.length && cells[column]
			if (dark && start < 0) {
				start = column
			} else if (!dark && start >= 0) {
				path += 'M' + (start + MARGIN) + ' ' + (row + MARGIN) + 'h' + (column - start) + 'v1h-' + (column - start) + 'z'
				start = -1
			}
		}
	})
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges">'
		+ '<rect width="' + size + '" height="' + size + '" fill="#ffffff"/>'
		+ '<path fill="#000000" d="' + path + '"/></svg>'
}

/** The code as a PNG, `pixels` wide, drawn straight from the modules so the edges stay sharp. */
export function qrPng(text: string, pixels: number = 1024): Promise<Blob> {
	const rows = qrModules(text)
	const size = rows.length + MARGIN * 2
	const scale = Math.max(1, Math.floor(pixels / size))
	const canvas = document.createElement('canvas')
	canvas.width = canvas.height = size * scale
	const context = canvas.getContext('2d')
	if (!context) {
		return Promise.reject(new Error('no canvas'))
	}
	context.fillStyle = '#ffffff'
	context.fillRect(0, 0, canvas.width, canvas.height)
	context.fillStyle = '#000000'
	rows.forEach((cells, row) => {
		cells.forEach((dark, column) => {
			if (dark) {
				context.fillRect((column + MARGIN) * scale, (row + MARGIN) * scale, scale, scale)
			}
		})
	})
	return new Promise((resolve, reject) => {
		canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('no image'))), 'image/png')
	})
}

/** Hand a file to the browser to save. */
export function saveBlob(blob: Blob, filename: string): void {
	const link = document.createElement('a')
	link.href = URL.createObjectURL(blob)
	link.download = filename
	document.body.append(link)
	link.click()
	link.remove()
	setTimeout(() => URL.revokeObjectURL(link.href), 1000)
}
