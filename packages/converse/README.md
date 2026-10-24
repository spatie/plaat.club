# Bundled Laravel Converse

Runtime snapshot of `laravel/converse` at upstream commit
`8e2541677d8416a097c6de2a3754f4a2ed602ae9` (2026-10-23).
The original runtime files, Composer metadata, frontend packages and MIT license
are unchanged. Upstream PHP tests, workbench, development tooling and Git history
are not bundled.

The application installs this directory through a Composer path repository,
mirrored into `vendor/laravel/converse` rather than symlinked. Package identity,
the `Laravel\Converse` namespace, provider discovery and frontend import aliases
remain unchanged. No private repository credentials are required.

Treat this as third-party code: application formatters exclude this directory.

## Switching to a public release

1. Remove the `packages/converse` path repository from the root `composer.json`.
2. Require the desired public `laravel/converse` version and update its lock entry.
3. Confirm the public package includes the React/core frontend sources. Otherwise,
   install the published frontend packages and remove the vendor-source aliases
   from `vite.config.ts` and `tsconfig.json`.
4. Run `composer ci:check` and `npm run build`.
5. Remove this directory and its formatter exclusions once the public dependency
   is verified. Review upstream release notes for any API or migration changes.
