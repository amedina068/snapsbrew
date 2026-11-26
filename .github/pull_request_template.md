## Summary

<!-- Say what the change does and why. Link the issue, for example "Closes #12". -->

## Release note

<!-- If users can see the change, write one or two short sentences in plain text. The maintainer copies them to the release notes in the docs repository. If users cannot see the change, remove this section. -->

## Checks

- [ ] `composer test` (or `vendor/bin/phpunit`) passes.
- [ ] `composer lint` passes.
- [ ] `composer api-docs` shows no change in `docs/api` (when `ApiSpec.php` changes).
- [ ] `npm run build` passes (when the front-end changes).
- [ ] `npm run test:editor` passes (when the editor changes).
- [ ] `php artisan migrate:fresh --seed` passes (when a migration changes).
- [ ] The PR title follows Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:` and similar).

## Compatibility

- [ ] The change has no database, configuration or public API change, or the change is described below.
- [ ] The MCP tools and the REST API keep their contract, or the change is described below.
- [ ] A screenshot or a terminal output is attached for a visible change.

<!-- Describe the migration, the configuration, the API change and how to roll back. -->
