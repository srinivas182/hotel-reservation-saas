# Security checks (S16)

Automatic checks that run on every pull request, and what they guarantee.

| Check                    | Where                                                        | Guarantees                                                                                                                                                                                                                    |
| ------------------------ | ------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hotel route permissions  | `tests/Feature/Security/HotelRouteAuthorizationTest.php`     | Every hotel route checks a permission, except a short reviewed list (with reasons); each of the six roles opens exactly the screens its permissions allow.                                                                    |
| Platform route abilities | `tests/Feature/Platform/PlatformOperationsTest.php`          | Every platform route checks a platform ability (S15).                                                                                                                                                                         |
| Hotels kept apart        | `tests/Feature/Tenancy/HotelOwnedModelsTest.php`             | Every table with a hotel_id is scoped to the current hotel, except a reviewed list.                                                                                                                                           |
| Encrypted columns        | `tests/Feature/Security/SensitiveDataTest.php`               | Secrets (tokens, keys, provider credentials) and personal notes (guests, bookings, events, tables, orders, messages, internal notes) are stored encrypted.                                                                    |
| Logs                     | `App\Support\Logging\RedactSensitiveData` (all log channels) | Phone numbers, email addresses, tokens and keys are replaced before anything is written.                                                                                                                                      |
| Public routes            | `tests/Feature/Security/PublicRoutesTest.php`                | Every public route that does work or shows data is rate limited; the private file store is never served directly; developer tools are closed.                                                                                 |
| Guest files              | `App\Domains\Conversations\Media\MediaPolicy`                | Only images, audio, video, PDF and office documents from guests are stored; others (programs, scripts, web pages, SVG) are refused. Documents are downloaded, never shown in the page, and every file is served in a sandbox. |
| Weekly scan              | `.github/workflows/security-scan.yml`                        | OWASP ZAP baseline against the running app every Monday; report in the run summary and as an artifact.                                                                                                                        |

## Settings

- `SESSION_SECURE_COOKIE` defaults to `true` outside local and testing.
- `CSP_ENFORCE=true` switches the Content Security Policy from report-only to enforced. Turn it on after a week of reports on staging (S16.4).
- Horizon needs a signed-in super admin and is rate limited.
