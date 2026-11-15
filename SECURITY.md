# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report them privately through GitHub's [private vulnerability reporting](../../security/advisories/new) with a description, the impact and steps to reproduce. You will get an answer within a few days, and a fix will be released as soon as possible. Reporters are credited unless they prefer otherwise.

## Supported versions

Only the latest release receives security fixes.

## Hardening tips for operators

- Serve Tocsin over **HTTPS** only.
- Keep **open registration** disabled unless you need it; invite users instead.
- Prefer **access tokens** over passwords in scripts, one token per script, and revoke unused ones.
- Back up `/data/app.key` securely: it encrypts sessions and stored secrets.
