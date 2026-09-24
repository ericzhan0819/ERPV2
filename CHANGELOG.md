# Changelog

All notable changes to the public ERPV2 project will be documented in this file.

The format is based on Keep a Changelog, and this project uses Semantic Versioning for public releases.

## [Unreleased]

### Security

- Reject unknown roles when reading internal vehicles, vehicle money entries, and photos.
- Share the login account rate limit with current-password verification during password changes.
- Restrict cash-account filtering of money entries to admin and manager, including the frontend filter controls.

## [1.0.0] - 2026-09-23

### Added

- Vehicle inventory workflow for preparing, listed, reserved, sold, and cancelled states.
- Customer management with buyer and seller vehicle relationships.
- Money entry workflow with approval states and vehicle-linked income / expense shortcuts.
- Cash account balances based on approved entries.
- Role-based access for admin, manager, and sales users.
- Backend-level financial field masking and authorization boundaries.
- Salary profiles, commission plans, monthly settlement workflow, and salary payout entries.
- Vehicle photo upload, thumbnail generation, cover selection, ordering, retry cleanup, and idempotency protection.
- Public read-only vehicle API for website integration.
- Audit logs for important authentication and data mutations.
- Username / Email login, mandatory first-login password change, and self-service account management.
- Responsive React interface with light / dark mode, mobile navigation, filters, and accessibility foundations.
- Docker Compose development database configuration.
- Backend and frontend automated test suites.

### Changed

- Composer dependency resolution targets PHP 8.3 as the compatibility baseline, keeping the lockfile installable on the documented minimum PHP version.

### Security

- Laravel Sanctum SPA cookie / Session authentication.
- Trusted host and trusted proxy configuration boundaries.
- Public vehicle resources separated from internal vehicle resources.
- Password and sensitive financial data excluded from API resources and audit payloads where appropriate.
