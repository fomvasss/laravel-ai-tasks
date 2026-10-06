import { fileURLToPath } from 'node:url';
import { defineConfig, passthroughImageService } from 'astro/config';
import starlight from '@astrojs/starlight';
import mermaid from 'astro-mermaid';
import starlightGitHubAlerts from 'starlight-github-alerts';
import starlightLinksValidator from 'starlight-links-validator';
import { remarkPlainMarkdown } from './src/remark-plain-markdown.mjs';

const base = '/laravel-ai-tasks';

export default defineConfig({
	site: 'https://fomvasss.github.io',
	base,
	// keeps the animated dashboard GIF as is instead of converting it to a static WebP
	image: { service: passthroughImageService() },
	markdown: {
		remarkPlugins: [[remarkPlainMarkdown, { root: fileURLToPath(new URL('.', import.meta.url)), base }]],
	},
	integrations: [
		mermaid(),
		starlight({
			title: 'Laravel AI Tasks',
			description: 'AI task orchestrator for Laravel on top of laravel/ai',
			social: [{ icon: 'github', label: 'GitHub', href: 'https://github.com/fomvasss/laravel-ai-tasks' }],
			// the pages live in docs/ itself, not in src/content/docs/
			markdown: { processedDirs: ['.'] },
			expressiveCode: { shiki: { langAlias: { env: 'dotenv' } } },
			editLink: { baseUrl: 'https://github.com/fomvasss/laravel-ai-tasks/edit/3.x/docs/' },
			plugins: [starlightGitHubAlerts(), starlightLinksValidator()],
			sidebar: [
				{ label: 'Getting started', items: [{ label: 'Overview', slug: 'index' }, 'installation', 'configuration'] },
				{
					label: 'Usage',
					items: [
						'usage/tasks',
						'usage/running-tasks',
						'usage/queued-tasks',
						'usage/routing',
						'usage/structured-output',
						'usage/tools',
						'usage/tool-approval',
						'usage/modalities',
						'usage/budgets',
						'usage/costs',
						'usage/provider-override',
						'usage/dashboard',
						'usage/webhooks',
						'usage/testing',
					],
				},
				{
					label: 'Reference',
					items: [
						'reference/facade',
						'reference/task',
						'reference/payload-response',
						'reference/ai-runs',
						'reference/events',
						'reference/commands',
						'reference/providers',
					],
				},
				{
					label: 'Guides',
					items: [
						'guides/production',
						'guides/chat-assistant',
						'guides/tools-in-practice',
						'guides/provider-quirks',
						'guides/testing-in-practice',
					],
				},
				'upgrading',
			],
		}),
	],
});
