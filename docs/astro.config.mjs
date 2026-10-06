import { fileURLToPath } from 'node:url';
import { defineConfig, passthroughImageService } from 'astro/config';
import starlight from '@astrojs/starlight';
import mermaid from 'astro-mermaid';
import starlightGitHubAlerts from 'starlight-github-alerts';
import starlightLinksValidator from 'starlight-links-validator';
import { remarkPlainMarkdown } from './src/remark-plain-markdown.mjs';

const base = '/laravel-billing';

export default defineConfig({
	site: 'https://fomvasss.github.io',
	base,
	image: { service: passthroughImageService() },
	markdown: {
		remarkPlugins: [[remarkPlainMarkdown, { root: fileURLToPath(new URL('.', import.meta.url)), base }]],
	},
	integrations: [
		mermaid(),
		starlight({
			title: 'Laravel Billing',
			description: 'Payments, subscriptions, refunds and invoices for Laravel with pluggable gateways',
			social: [{ icon: 'github', label: 'GitHub', href: 'https://github.com/fomvasss/laravel-billing' }],
			// the pages live in docs/ itself, not in src/content/docs/
			markdown: { processedDirs: ['.'] },
			expressiveCode: { shiki: { langAlias: { env: 'dotenv' } } },
			editLink: { baseUrl: 'https://github.com/fomvasss/laravel-billing/edit/master/docs/' },
			plugins: [starlightGitHubAlerts(), starlightLinksValidator()],
			sidebar: [
				{ label: 'Getting started', items: [{ label: 'Overview', slug: 'index' }, 'installation', 'configuration'] },
				{
					label: 'Usage',
					items: [
						'usage/payments',
						'usage/return-pages',
						'usage/fiscal-receipts',
						'usage/refunds',
						'usage/saved-cards',
						'usage/subscriptions',
						'usage/trials',
						'usage/renewals',
						'usage/usage-quotas',
						'usage/provider-managed',
						'usage/invoices',
						'usage/webhooks',
						'usage/scheduling',
						'usage/multi-tenancy',
						'usage/money',
						'usage/testing',
					],
				},
				{
					label: 'Gateways',
					items: [
						'usage/gateways',
						'usage/gateways/monobank',
						'usage/gateways/liqpay',
						'usage/gateways/wayforpay',
						'usage/gateways/hutko',
						'usage/gateways/stripe',
						'usage/gateways/paddle',
						'usage/gateways/fake',
					],
				},
				{
					label: 'Guides',
					items: [
						'guides/production',
						'guides/use-cases',
						'guides/architecture',
						'guides/writing-a-gateway',
						'guides/webhook-testing',
					],
				},
				{
					label: 'Reference',
					items: [
						'reference/billing-facade',
						'reference/models',
						'reference/database',
						'reference/events',
						'reference/commands',
						'reference/contracts',
						'reference/dto',
						'reference/routes',
					],
				},
				'upgrading',
			],
		}),
	],
});
