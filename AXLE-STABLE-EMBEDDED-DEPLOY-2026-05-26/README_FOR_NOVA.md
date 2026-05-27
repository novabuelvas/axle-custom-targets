# AXLE Stable Embedded Deploy - Nova/Codex

Prepared by: Codex
Date: 2026-05-26

## Use This Package

This is the recovery package Nova requested: the known-good large embedded-image frontend from the May 19 build, paired with the working PHP proof endpoint.

Do not rebuild the HTML. Do not paste it into a CMS. Do not mix it with the current broken server file.

## Files

Upload:

```text
custom-branded-shooting-targets/index.html
```

to:

```text
/var/www/html/custom-branded-shooting-targets/index.html
```

Upload:

```text
api/axle-proof-request.php
```

to:

```text
/var/www/html/api/axle-proof-request.php
```

## Why This Package

Local browser verification passed on this exact frontend:

- AXLE logo renders.
- Landing hero renders.
- Full Service button opens the full-service form.
- DIY button opens the DIY designer.
- Browser console showed no JavaScript errors.
- The page contains embedded images as `data:image` assets.
- The page contains no `submit.php` reference.
- The page submits to `/api/axle-proof-request.php`.

The two literal `src=""` attributes in the source are expected. They are populated on startup by `setHeroBackgrounds()` / `renderTemplates()` from the embedded `ASSETS` object. Do not "fix" them by hand.

## Deploy

From this folder:

```bash
bash scripts/deploy-stable-embedded.sh
```

Default server settings:

```text
HOST=159.65.189.43
SSH_USER=root
SSH_KEY=~/.ssh/do_nova
REMOTE_ROOT=/var/www/html
```

Override example:

```bash
SSH_KEY=/path/to/key HOST=159.65.189.43 bash scripts/deploy-stable-embedded.sh
```

## Verify After Deploy

Open:

```text
https://go.axletargets.com/custom-branded-shooting-targets/
```

Hard-refresh.

Confirm:

- AXLE logo visible.
- Hero image visible.
- Full Service "Click Here" opens the full-service form.
- DIY "Click Here" opens the DIY designer.
- Browser console has no JavaScript syntax errors.

Run these source checks:

```bash
curl -fsSL https://go.axletargets.com/custom-branded-shooting-targets/ | grep "/api/axle-proof-request.php"
curl -fsSL https://go.axletargets.com/custom-branded-shooting-targets/ | grep -v "submit.php" >/dev/null
```

For the second command, success means no output and exit 0.

## Email Test

Do not run an email test unless Alejandro explicitly authorizes it.

If authorized:

```bash
RUN_EMAIL_TEST=1 bash scripts/deploy-stable-embedded.sh
```

The backend requires `SENDGRID_API_KEY` unless `AXLE_ALLOW_PHP_MAIL_FALLBACK=1` is explicitly set.

