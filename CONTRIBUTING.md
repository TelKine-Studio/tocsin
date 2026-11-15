# Contributing to Tocsin

Thanks for your interest in Tocsin! Bug reports, ideas and pull requests are welcome.

## Getting started

See the *Development* section of the [README](README.md) to run the server and the mobile app locally.

## Guidelines

- **Open an issue first** for significant changes, so we can agree on the approach.
- **Keep pull requests focused**: one feature or fix per PR.
- **Tests**: add or update tests. The server suite (`php artisan test`) and the mobile suite (`flutter test`) must pass.
- **Code style**:
  - PHP: [Laravel Pint](https://laravel.com/docs/pint) — run `./vendor/bin/pint` in `server/`.
  - Dart: `dart format .` and `flutter analyze` in `mobile/`.
- **Translations**: user-facing strings use `__()` on the server (French in `server/lang/fr.json`) and `S.of(context).t()` in the app (`mobile/lib/l10n/strings.dart`). Please update both languages.
- **Configuration**: Tocsin is configured from its web interface. Avoid introducing new environment variables unless they describe the runtime environment.

## Commit messages

Use short, imperative messages (`Add topic search`, `Fix token revocation`). Reference issues when relevant (`Fixes #12`).
