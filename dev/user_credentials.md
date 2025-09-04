# Development: Usernames & Passwords (DEV ONLY)

WARNING: This file is for local development only. Do NOT commit or publish this file to any public repository or share it outside your dev environment. It contains or references plaintext passwords which are sensitive.

Purpose: a single place to track developer-facing accounts and known development passwords. For security, most production accounts remain hashed in `database/data/users.json`; use the seed script to (re)create simple dev passwords when needed.

---

## Current users (from `database/data/users.json`)

- Username: `admin`  
  - id: 2  
  - role: admin  
  - dev password: `password123` (seeded by `dev/seed_users.php`)

---

## How to (re)generate or view dev plaintext passwords

1. From the project root, run the seed script to ensure the known dev accounts exist and to get their plaintext passwords in the JSON response:

```powershell
php .\dev\seed_users.php
```

- The script is intentionally restricted to localhost (or CLI) for safety. When it inserts users it returns a JSON list that includes `plain_password` for the seeded accounts.

2. If you need to set a specific plaintext password for a dev user for testing, consider one of these safe options:
- Use the seed script (add the username/password to the `$seed` array in `dev/seed_users.php`), then run it from CLI to create the user and return the `plain_password` in the response.
- Or create a short dev-only script to update a single user's password (hashing it). I can add one if you'd like.

## Recommendations

- Keep this file out of production and do not push it to remote repositories. Consider adding `dev/user_credentials.md` to `.gitignore` if not already ignored.
- For ephemeral dev credentials, prefer running `dev/seed_users.php` to get reproducible passwords rather than storing many plaintext passwords.

---

If you want, I can:
- Add more known dev accounts to `dev/seed_users.php` (e.g., `kimble` with a known dev password) and update this file automatically.
- Add a helper `dev/set_password.php` CLI script to set a plaintext password for an existing user (it will hash before saving).
- Add `dev/user_credentials.json` instead of markdown if you prefer machine-readable formatting.
