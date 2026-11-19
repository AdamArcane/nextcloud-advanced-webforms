/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { InjectionKey } from 'vue'

import { inject } from 'vue'

/**
 * Whether the person may do more than read responses: delete them and see the delivery log
 * (docs/SCHEMA.md section 22.1). The server refuses either way; this keeps the buttons from being
 * offered. A Reviewer is the only one who gets false.
 */
export const CAN_MANAGE_RESPONSES: InjectionKey<boolean> = Symbol('webforms.canManageResponses')

export function useCanManageResponses(): boolean {
	return inject(CAN_MANAGE_RESPONSES, true)
}
