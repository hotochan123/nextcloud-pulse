/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Pure style entry point: bundles the token layer and the design system for
 * pages WITHOUT a Vue bundle — currently the embed shell (js/pulse-embed.js).
 *
 * Why a separate entry and not a copy in css/embed.css: the shell should use
 * the same roles and the same ONE button (.pulse-btn) as the rest
 * of the app. A second copy would drift as soon as a token changes.
 */
import './styles/pulse-tokens.css'
import './styles/pulse-ds.css'
