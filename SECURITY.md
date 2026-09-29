# Security hardening — what changed and what you MUST do after deploy

## Removed from the codebase (deleted, not gated)

These files were reachable without authentication and allowed full account takeover or
database reset/mutiny. They are **gone from the repo** — do not deploy old builds:

| File | Why it was dangerous |
|---|---|
| `sso_test.php` | Minted valid SSO JWTs for any school → total auth bypass |
| `db/seed_demo.php` | Reset the super-admin password to a known value on every GET |
| `db/create_admin.php` | Created known-password `admin` / `schooladmin` accounts on GET |
| `db/setup.php` | Re-ran schema DDL unauthenticated |
| `db/sample_data.php` | Wrote sample data into the live database on GET |
| `db/seed_bands.php` | Wrote band data on GET |
| `db/fix_band_lessons.php` | Ran a one-time migration on every GET |
| `debug_db.php` | Dumped generation rows, paths and logs unauthenticated |
| `schema_check.php` | Dumped schema + user rows unauthenticated |
| `diagnose_parsing.php` | Dumped DB internals unauthenticated |
| `admin/mobile_test.php` | Unauth test page |
| `admin/pdf_debug.php` | Diagnostic dump (behind login, debug-only) |
| `admin/edit_test.php`, `admin/localization_test.php` | Test pages |
| `check_alignment.php`, `test_kenya_features.php` | Dev-only check scripts |

If you ever need seeding/setup tooling again: run it from a trusted machine against the
database directly, or behind an authenticated super-admin action. **Never as a public URL.**

## Also hardened in the same change

- **Login** (`login.php`): 5 failed attempts per username → 60-second lockout (session-based
  helpers in `db/db.php`), `session_regenerate_id(true)` on success, CSRF token on the form.
- **Error handler** (`db/error_handler.php`): message/file/line/stack now shown **only** when
  `APP_ENV=development`. Production shows a generic error; details go to the server log only.

## REQUIRED after deploying this change (do not skip)

1. **Rotate `SSO_SHARED_SECRET`** on Render (and in EduCore to the same new value). The old
   secret must be considered compromised — it was exposed by `sso_test.php`.
2. **Change the super-admin password** (it was resettable to a known value by anyone).
3. **Change every demo/school admin password** created by the deleted seed scripts.
4. Confirm on live after deploy: `GET /sso_test.php` → 404, `GET /db/seed_demo.php` → 404.
