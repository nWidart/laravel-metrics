# Laravel Metrics documentation

The source of https://nwidart.github.io/laravel-metrics/, built with [Starlight](https://starlight.astro.build/).

The pages follow [Diátaxis](https://diataxis.fr/). Each page has one type, and lives in the folder of that type under `src/content/docs/`:

| Folder | Type | Serves |
|---|---|---|
| `tutorials/` | Tutorial | A newcomer who learns by doing one concrete thing |
| `guides/` | How-to guide | A user with a goal |
| `reference/` | Reference | A user who looks up a fact |
| `explanation/` | Explanation | A reader who wants to understand why |

Add a page to the sidebar in `astro.config.mjs`. Link to other pages with absolute paths that include the base and a trailing slash, such as `/laravel-metrics/reference/configuration/`.

## Develop

Requirements: Node.js 22.23 or later.

```bash
npm ci
npm run dev     # http://localhost:4321/laravel-metrics/
npm run build   # fails on a broken internal link
```

## Deploy

`.github/workflows/docs.yml` builds the site on every pull request that changes `docs/`, and deploys it to GitHub Pages on every push to `main`.
