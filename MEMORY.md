# Git memory

## jori.com speed checks (30 Aug 2026)

Do not probe `https://www.jori.com/robots.txt` for speed or availability.

That file was a workaround while Cloudflare bot protection blocked the real pages. Bot protection was changed. Check the live site:

- Homepage: https://www.jori.com/
- Language homepages: `/nl`, `/fr`, `/en`, `/de`

`robots.txt` is the Drupal crawler file only. It is not a health check.

Verified after the change: homepage returns HTTP 200 (Drupal 7 HTML via Cloudflare).
