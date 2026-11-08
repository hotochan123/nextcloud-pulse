// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A plain PNG of any size for the image questions of the stress catalog (S8.5): horizontal stripes, so that a
// cut or squeezed image shows. No dependency beyond node:zlib.

import { deflateSync } from 'node:zlib'

const CRC = new Int32Array(256).map((_, n) => {
	let c = n
	for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1
	return c
})
const crc32 = (buf) => {
	let c = -1
	for (const b of buf) c = CRC[(c ^ b) & 0xFF] ^ (c >>> 8)
	return (c ^ -1) >>> 0
}
const chunk = (type, data) => {
	const len = Buffer.alloc(4)
	len.writeUInt32BE(data.length)
	const td = Buffer.concat([Buffer.from(type, 'latin1'), data])
	const crc = Buffer.alloc(4)
	crc.writeUInt32BE(crc32(td))
	return Buffer.concat([len, td, crc])
}

/** A width × height RGB PNG with stripes every 100 px. */
export function stripes(width, height) {
	const row = width * 3 + 1
	const raw = Buffer.alloc(row * height)
	for (let y = 0; y < height; y++) {
		const dark = Math.floor(y / 100) % 2 === 0
		raw[y * row] = 0
		for (let x = 0; x < width; x++) {
			const o = y * row + 1 + x * 3
			raw[o] = dark ? 40 : 200
			raw[o + 1] = dark ? 90 : 160
			raw[o + 2] = dark ? 160 : 60
		}
	}
	const ihdr = Buffer.alloc(13)
	ihdr.writeUInt32BE(width, 0)
	ihdr.writeUInt32BE(height, 4)
	ihdr[8] = 8
	ihdr[9] = 2
	return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}
