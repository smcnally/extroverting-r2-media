# Extroverting R2 Media

Cloudflare R2 + Workers AI enrichment for the WordPress Media Library.

## What it does

1. On attachment upload (`add_attachment`), schedules async AI enrichment via WP-Cron.
2. Sends the image (base64) to the Cloudflare Worker (`/enrich` route), which uses
   Workers AI (Llama 3 for description/title/tags, DETR-ResNet-50 for object detection).
3. Saves results to post meta (`_ext_ai_*`) and updates the attachment's
   content, title, tags, and alt text in WordPress.
4. Writes metadata back into the **source file** with exiftool (`Ext_Exif_Writer`):

   | Format | Fields written |
   | ------ | -------------- |
   | PNG    | `Description`, `Title`, `Collection` (stored as tEXt chunks) |
   | JPEG   | `EXIF:ImageDescription` |
   | XMP (PNG/JPEG/TIFF/WebP) | `Description`, `ImageTitle`, `Categories` (joined tags + categories), `ModifyDate`, `AltTextAccessibility-en-US` (mirrors Description) |

## Configuration

Settings > Media in wp-admin (or options table):

- `ext_ai_worker_url` — Cloudflare Worker base URL
- `ext_ai_worker_token` — Bearer token for the Worker
- `ext_exiftool_binary` — exiftool binary name (PATH-resolved) or absolute path
  (override also via `ext_r2_media_exiftool_binary` filter)

## Local dev notes

- Local site: http://127.0.0.1:8099/ — Docker container `extroverting-dev`
  (image `local/php-mysql:8.1`), serving `../extroverting-theme-repo` at `/app`.
- The plugin runs inside that container, so **exiftool must be installed there**:

  ```sh
  docker exec extroverting-dev apt-get update && \
    docker exec extroverting-dev apt-get install -y libimage-exiftool-perl
  ```

  This is ephemeral — repeat it if the container is recreated (or bake it into
  the image).
- The deployed copy lives at
  `../extroverting-theme-repo/wordpress/wp-content/plugins/extroverting-r2-media/`
  (copied, not symlinked, matching the `extroverting-core` convention).
  Re-copy after making changes here.
- The Worker code lives in `./cloudflare-worker/` (wrangler deploy required for
  end-to-end enrichment; without it, enrichment status is `failed` by design).
  Deploy steps and notes are in `cloudflare-worker/DEPLOY.md`.

## Files

- `extroverting-r2-media.php` — plugin bootstrap, settings, cron wiring
- `includes/class-ext-ai-enrichment.php` — Worker request/response, post meta, batch cron
- `includes/class-ext-exif-writer.php` — exiftool metadata writer (shell-safe, idempotent)