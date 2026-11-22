// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightLinksValidator from 'starlight-links-validator';
import starlightLlmsTxt from 'starlight-llms-txt';

// Served by GitHub Pages at https://nwidart.github.io/laravel-metrics/.
export default defineConfig({
	site: 'https://nwidart.github.io',
	base: '/laravel-metrics',
	trailingSlash: 'always',
	integrations: [
		starlight({
			title: 'Laravel Metrics',
			description:
				'OpenTelemetry metrics for Laravel that work under PHP-FPM, with zero-code HTTP, database, queue and cache instrumentation.',
			favicon: '/favicon.svg',
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/nWidart/laravel-metrics' },
			],
			editLink: {
				baseUrl: 'https://github.com/nWidart/laravel-metrics/edit/main/docs/',
			},
			lastUpdated: true,
			plugins: [
				// Fails the build on a broken internal link or anchor.
				starlightLinksValidator(),
				// Publishes /llms.txt, /llms-full.txt and /llms-small.txt for language models.
				starlightLlmsTxt({
					projectName: 'Laravel Metrics',
					description:
						'nwidart/laravel-metrics records OpenTelemetry metrics in Laravel applications under PHP-FPM, merges each request into a shared Redis store, and exports the cumulative values to Prometheus or over OTLP.',
				}),
			],
			sidebar: [
				{
					label: 'Tutorials',
					items: [{ slug: 'tutorials/first-metrics' }],
				},
				{
					label: 'How-to guides',
					items: [
						{
							label: 'Set up',
							items: [
								{ slug: 'guides/install-in-production' },
								{ slug: 'guides/develop-without-redis' },
							],
						},
						{
							label: 'Export',
							items: [
								{ slug: 'guides/scrape-with-prometheus' },
								{ slug: 'guides/push-over-otlp' },
								{ slug: 'guides/exclude-metrics' },
							],
						},
						{
							label: 'Record',
							items: [
								{ slug: 'guides/record-custom-metrics' },
								{ slug: 'guides/add-attributes' },
								{ slug: 'guides/read-at-export-time' },
								{ slug: 'guides/ignore-routes' },
								{ slug: 'guides/turn-off-instrumentation' },
								{ slug: 'guides/flush-long-running-work' },
							],
						},
						{
							label: 'Test and operate',
							items: [
								{ slug: 'guides/test-metrics' },
								{ slug: 'guides/reduce-overhead' },
								{ slug: 'guides/share-a-redis' },
								{ slug: 'guides/troubleshoot' },
							],
						},
					],
				},
				{
					label: 'Reference',
					items: [
						{ slug: 'reference/configuration' },
						{ slug: 'reference/facade' },
						{ slug: 'reference/testing' },
						{ slug: 'reference/metrics-push' },
						{ slug: 'reference/prometheus-endpoint' },
						{ slug: 'reference/stores' },
						{
							label: 'Built-in metrics',
							items: [
								{ slug: 'reference/metrics/http' },
								{ slug: 'reference/metrics/database' },
								{ slug: 'reference/metrics/queue' },
								{ slug: 'reference/metrics/cache' },
								{ slug: 'reference/metrics/scheduler' },
								{ slug: 'reference/metrics/exceptions' },
								{ slug: 'reference/metrics/export-time' },
							],
						},
						{ slug: 'reference/requirements' },
						{ slug: 'reference/limits' },
						{
							label: 'Changelog',
							link: 'https://github.com/nWidart/laravel-metrics/blob/main/CHANGELOG.md',
							attrs: { target: '_blank', rel: 'noopener' },
						},
					],
				},
				{
					label: 'Explanation',
					items: [
						{ slug: 'explanation/how-it-works' },
						{ slug: 'explanation/stores' },
						{ slug: 'explanation/scrape-or-push' },
						{ slug: 'explanation/naming' },
						{ slug: 'explanation/cardinality' },
						{ slug: 'explanation/failure-handling' },
						{ slug: 'explanation/performance' },
					],
				},
			],
		}),
	],
});
