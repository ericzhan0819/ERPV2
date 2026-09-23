# Security Policy

## Supported Versions

Security fixes are applied to the latest public release of ERPV2.

| Version | Supported |
| --- | --- |
| 1.x | Yes |
| < 1.0 | No |

## Reporting a Vulnerability

Please do not report security vulnerabilities in a public GitHub issue.

Preferred reporting path:

1. Open the repository on GitHub.
2. Go to **Security**.
3. Use **Report a vulnerability** / private vulnerability reporting when available.

If private vulnerability reporting is not available, contact the repository maintainer privately through GitHub before disclosing technical details publicly.

Please include:

- affected version or commit;
- affected endpoint, workflow, or component;
- reproduction steps;
- expected and actual behavior;
- impact assessment;
- proof of concept, if available;
- suggested mitigation, if known.

Avoid including real customer data, production credentials, session cookies, API secrets, database dumps, or other sensitive information in the report.

## Scope

Security-sensitive areas include, but are not limited to:

- authentication and Session handling;
- authorization and role boundaries;
- financial field masking;
- customer and employee personal information;
- vehicle photo upload and storage handling;
- public vehicle API exposure;
- file path / host / proxy handling;
- audit log integrity;
- salary and money-entry workflows.

## Deployment Responsibility

ERPV2 is provided under the MIT License without warranty. Operators are responsible for secure production configuration, including HTTPS, database credentials, Session / Cookie settings, trusted hosts / proxies, backups, filesystem permissions, and dependency updates.
