/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Minimaler Toast-Ersatz für @nextcloud/dialogs.
// Grund: dialogs zog ~3 MB faule Chunks (FilePicker, rehype-highlight) ins Bundle,
// nur damit showError() eine Meldung zeigt. Hier komplett abhängigkeitsfrei —
// funktioniert auf der angemeldeten Moderator- UND der öffentlichen Gast-Seite,
// folgt dem NC-Theme über CSS-Variablen und respektiert prefers-reduced-motion.
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
 * Zeigt eine kurze Fehlermeldung. Auto-Dismiss nach `timeout` ms, Klick schließt.
 * Signatur-kompatibel zur bisherigen showError-Nutzung.
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

/** Kurze Fehlermeldung (rot, role=alert). Klick oder Timeout schließt. */
export function showError(message, timeout = 5000) {
	return showToast(message, { variant: 'error', role: 'alert', timeout })
}

/** Kurze Erfolgsmeldung (gruen, role=status). */
export function showSuccess(message, timeout = 3000) {
	return showToast(message, { variant: 'success', role: 'status', timeout })
}
