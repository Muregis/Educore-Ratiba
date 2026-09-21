# EduCore SMS ↔ Ratiba SSO (duo link)

## Overview

| System | Role |
|--------|------|
| **EduCore SMS** | School management — issues short-lived JWT |
| **EduCore Ratiba** | Timetable — verifies JWT at `sso.php`, starts session |

Databases stay separate. Only identity is shared (`educore_school_id` + admin username).

## 1. Shared secret

```bash
openssl rand -hex 32
```

Set the **same** value on:

- Render → Ratiba service → `SSO_SHARED_SECRET`
- EduCore SMS config / env → `SSO_SHARED_SECRET`

## 2. EduCore SMS: Timetable button

Redirect the logged-in school admin to:

```
https://educore-ratiba.onrender.com/sso.php?token=<JWT>
```

JWT claims (HS256):

```json
{
  "educore_school_id": "12345",
  "educore_school_name": "St. Mary's Primary",
  "educore_admin_username": "headteacher",
  "exp": 1726920000
}
```

`exp` should be ~5 minutes from issue time.

### PHP snippet for EduCore

```php
function educoreRatibaSsoUrl(string $schoolId, string $schoolName, string $username): string
{
    $secret = getenv('SSO_SHARED_SECRET') ?: '';
    $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'educore_school_id'      => (string) $schoolId,
        'educore_school_name'    => $schoolName,
        'educore_admin_username' => $username,
        'exp'                    => time() + 300,
    ])), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
    ), '+/', '-_'), '=');
    return 'https://educore-ratiba.onrender.com/sso.php?token=' . urlencode("{$header}.{$payload}.{$sig}");
}
```

## 3. Ratiba behaviour

1. Verifies signature and `exp`
2. Finds school by `educore_school_id` (or creates it)
3. Finds/creates `school_admins` row for the username
4. Starts session → `admin/dashboard.php`

## 4. Test on Ratiba

1. Set `SSO_SHARED_SECRET` on Render
2. Open `https://educore-ratiba.onrender.com/sso_test.php`
3. You should land on the school admin dashboard

After testing, restrict or remove `sso_test.php` in production.

## 5. Optional: link existing school

In SQL (Supabase):

```sql
UPDATE schools SET educore_school_id = '12345' WHERE id = 1;
```

Or set **EduCore school ID** when creating a school in Ratiba super-admin.
