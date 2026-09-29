/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Reiner Stil-Einstiegspunkt: bündelt Token-Schicht und Design-System für
 * Seiten OHNE Vue-Bundle — heute die Einbett-Shell (js/pulse-embed.js).
 *
 * Warum ein eigener Einstieg und keine Kopie in css/embed.css: die Shell soll
 * dieselben Rollen und denselben EINEN Button (.pulse-btn) nutzen wie der Rest
 * der App. Eine zweite Kopie würde driften, sobald sich ein Token ändert.
 */
import './styles/pulse-tokens.css'
import './styles/pulse-ds.css'
