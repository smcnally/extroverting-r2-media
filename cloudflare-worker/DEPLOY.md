# Deploying the Extroverting Media Enrichment Worker

One-time setup + deploy from this directory (`extroverting-r2-media/cloudflare-worker/`).

## Prereqs

- Node 18+ (`npx` available)
- Cloudflare auth: `npx wrangler login` (OAuth) or `CLOUDFLARE_API_TOKEN` env var.
  Verify with `npx wrangler whoami`.

## 1. Create the resources

```sh
npx wrangler r2 bucket create extroverting-media
npx wrangler kv namespace create METADATA_CACHE
```

Copy the KV namespace id from the command output into `wrangler.toml`
(`[[kv_namespaces]] id = ...`), replacing `REPLACE_WITH_KV_NAMESPACE_ID`.

## 2. Deploy

```sh
npx wrangler deploy
```

Note the deployed URL, e.g. `https://extroverting-media-enrichment.<account>.workers.dev`.

## 3. Set the auth secret

```sh
npx wrangler secret put WORKER_TOKEN
# paste a long random token (e.g. `openssl rand -hex 32`)
```

## 4. Configure WordPress

- WP admin → Settings → Media → "Cloudflare R2 Media" section
  - Worker URL: the deployed `*.workers.dev` URL
  - Worker Token: the same WORKER_TOKEN value
- Or via options table: `ext_ai_worker_url`, `ext_ai_worker_token`

## 5. Verify E2E

- Upload an image in the WP Media Library → WP-Cron triggers `/enrich`
- Attachment meta `_ext_ai_status` should become `completed`, `_ext_ai_exif_status` `success`
- Check the source file with `exiftool -G1 -a -s <file>` for the PNG tEXt + XMP fields
- Worker-side: Media → AI Enrichment table in wp-admin; `wrangler tail` for logs

## Local dev

```sh
npx wrangler dev          # local Miniflare sim of R2/KV; AI requires auth
curl -X POST http://localhost:8787/enrich -H 'Content-Type: application/json' \
  -d '{"image":"<base64>","filename":"test.png","media_id":1}'
```