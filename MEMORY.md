# Git memory

## jori.com speed checks (30 Aug 2026)

Do not probe `https://www.jori.com/robots.txt` for speed or availability.

That file was a workaround while Cloudflare bot protection blocked the real pages. Bot protection was changed. Check the live site:

- Homepage: https://www.jori.com/
- Language homepages: `/nl`, `/fr`, `/en`, `/de`

`robots.txt` is the Drupal crawler file only. It is not a health check.

Verified after the change: homepage returns HTTP 200 (Drupal 7 HTML via Cloudflare).

Production tracker: `https://api.imageert.be/uptime/` on OVH
`/home/imageee/api/uptime/check_uptime.php`. Still probing robots.txt until this folder is uploaded.

Drop-in files in `uptime/`:
- `fix_jori_probe.php` — upload next to the live script, open it once in a browser, then delete it
- `check_uptime.php` — full replacement if the patcher finds no robots.txt string

FTP: `ftp.cluster031.hosting.ovh.net` user `imageee`. No password is in this environment, so production was not written from here.
