/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Minimal toast replacement for @nextcloud/dialogs.
// Reason: dialogs pulled ~3 MB of lazy chunks (FilePicker, rehype-highlight) into the bundle
// just so that showError() can display a message. This one has no dependencies at all:
// it works on the signed-in moderator page AND the public guest page,
// follows the NC theme via CSS variables and respects prefers-reduced-motion.
import { t } from './util/l10n.js'

let container = null
let stylesInjected = false

function injectStyles() {
	if (stylesInjected) { return }
	stylesInjected = true
	const style = document.createElement('style')
	style.textContent = `
	.pulse-toasts { position: fixed; top: 12px; left: 50%; transform: translateX(-50%);
		z-index: 100000; display: flex; flex-direction: column; gap: 8px; align-items: center;
		pointer-events: none; max-width: 92vw; }
	.pulse-toast { pointer-events: auto; cursor: pointer;
		background: var(--color-error, #c73e3e); color: #fff;
		padding: 12px 18px; border-radius: var(--border-radius-large, 12px);
		box-shadow: 0 4px 16px rgba(0, 0, 0, .28); font-size: 15px; font-weight: 500;
		max-width: 480px; line-height: 1.35;
		animation: pulse-toast-in .18s cubic-bezier(.22, 1, .36, 1); }
	.pulse-toast.is-ok { background: var(--color-success, #2d7d46); }
	.pulse-toast.is-out { animation: pulse-toast-out .18s ease forwards; }
	@keyframes pulse-toast-in { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
	@keyframes pulse-toast-out { to { opacity: 0; transform: translateY(-8px); } }
	@media (prefers-reduced-motion: reduce) { .pulse-toast, .pulse-toast.is-out { animation: none; } }
	`
	document.head.appendChild(style)
}

function ensureContainer() {
	if (container && document.body.contains(container)) { return container }
	injectStyles()
	container = document.createElement('div')
	container.className = 'pulse-toasts'
	document.body.appendChild(container)
	return container
}

/**
 * Shows a short error message. Auto-dismiss after `timeout` ms, a click closes it.
 * Signature-compatible with the previous showError usage.
 */
function showToast(message, opts = {}) {
	const variant = opts.variant || 'error'
	const el = document.createElement('div')
	el.className = variant === 'success' ? 'pulse-toast is-ok' : 'pulse-toast'
	el.setAttribute('role', opts.role || 'alert')
	el.textContent = String(message || (variant === 'success' ? t('pulse', 'Done') : t('pulse', 'Error')))
	const remove = () => {
		if (!el.parentNode) { return }
		el.classList.add('is-out')
		setTimeout(() => el.remove(), 200)
	}
	el.addEventListener('click', remove)
	ensureContainer().appendChild(el)
	setTimeout(remove, opts.timeout || 5000)
	return { hideToast: remove }
}

/** Short error message (red, role=alert). A click or the timeout closes it. */
export function showError(message, timeout = 5000) {
	return showToast(message, { variant: 'error', role: 'alert', timeout })
}

/** Short success message (green, role=status). */
export function showSuccess(message, timeout = 3000) {
	return showToast(message, { variant: 'success', role: 'status', timeout })
}
