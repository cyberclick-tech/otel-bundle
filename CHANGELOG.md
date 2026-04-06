# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [0.1.0] - 2026-04-06

### BC Breaks

- **Namespace renamed**: root namespace changed from `CyberclickTech\OtelBundle` to `Cyberclick\OtelBundle`. All `use` statements, service FQCN references, and `bundles.php` registration must be updated.
- **Composer package renamed**: package name changed from `cyberclick-tech/otel-bundle` to `cyberclick/otel-bundle`. Update your `composer.json` accordingly.
- **Symfony 6.4 dropped**: minimum Symfony version is now 7.0. Projects on Symfony 6.4 should stay on `0.0.6`.

### Added

- Symfony 8.0 compatibility.

## [0.0.6] - 2026-03-15

### Changed

- Documentation updates.

## [0.0.5] - 2026-03-14

- Initial stable release with HTTP tracing, console tracing, Doctrine instrumentation, Messenger middleware, and Monolog log correlation.
