/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PropType, VNode } from 'vue'
import type { Block, Inline } from '../../markdown/parse.ts'

import { defineComponent, h } from 'vue'
import { parseMarkdown } from '../../markdown/parse.ts'

function inline(nodes: Inline[]): (VNode | string)[] {
	return nodes.map((node) => {
		switch (node.type) {
			case 'text':
				return node.text
			case 'br':
				return h('br')
			case 'strong':
				return h('strong', inline(node.children))
			case 'em':
				return h('em', inline(node.children))
			case 'link':
				// New tab, so following a link does not discard a half-filled form.
				return h('a', { href: node.href, target: '_blank', rel: 'noopener noreferrer nofollow' }, inline(node.children))
		}
	})
}

function block(node: Block): VNode {
	if (node.type === 'p') {
		return h('p', inline(node.children))
	}
	return h(node.type, node.items.map((item) => h('li', inline(item))))
}

/**
 * Renders limited markdown (docs/SCHEMA.md section 4.5) as elements. Content
 * never goes through innerHTML, so there is nothing to sanitize.
 */
export default defineComponent({
	name: 'MarkdownText',
	props: {
		source: { type: String as PropType<string>, required: true },
	},
	setup(props) {
		return () => h('div', { class: 'af-markdown' }, parseMarkdown(props.source).map(block))
	},
})
