/**
 * Extroverting Media Enrichment Worker
 *
 * Cloudflare Worker that handles:
 * 1. Media upload to R2
 * 2. AI-powered image description and title generation
 * 3. Metadata caching and indexing
 * 4. Serving enriched media files
 */

import { enrichImageWithAI } from './ai-enrichment.js';
import { handleCronBatchProcessing } from './cron-batch.js';

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const method = request.method;

    // CORS headers for WordPress API calls
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-WordPress-Nonce',
      'Access-Control-Max-Age': '86400',
    };

    if (method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    // Mutating routes require a bearer token once WORKER_TOKEN is configured.
    if (['/enrich', '/upload'].includes(url.pathname) && !isAuthorized(request, env)) {
      return new Response(JSON.stringify({ error: 'Unauthorized' }), {
        status: 401,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    try {
      // Route: POST /enrich - Enrich a single image with AI
      if (url.pathname === '/enrich' && method === 'POST') {
        const response = await handleEnrichRequest(request, env, ctx);
        return new Response(response.body, {
          status: response.status,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' }
        });
      }

      // Route: POST /upload - Upload media to R2
      if (url.pathname === '/upload' && method === 'POST') {
        const response = await handleUploadRequest(request, env, ctx);
        return new Response(response.body, {
          status: response.status,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' }
        });
      }

      // Route: GET /media/:path - Serve media from R2
      if (url.pathname.startsWith('/media/') && method === 'GET') {
        const response = await handleMediaRequest(request, env, ctx);
        return response;
      }

      // Route: GET /metadata/:filename - Get enrichment metadata
      if (url.pathname.startsWith('/metadata/') && method === 'GET') {
        const response = await handleMetadataRequest(request, env, ctx);
        return new Response(response.body, {
          status: response.status,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' }
        });
      }

      // Default: 404
      return new Response(JSON.stringify({ error: 'Not found' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });

    } catch (error) {
      console.error('Worker error:', error);
      return new Response(JSON.stringify({
        error: 'Internal server error',
        message: error.message
      }), {
        status: 500,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }
  },

  // Cron trigger for batch processing
  async scheduled(event, env, ctx) {
    console.log('Cron trigger fired:', event.cron);
    await handleCronBatchProcessing(env, ctx);
  }
};

/**
 * Bearer-token check for mutating routes. Auth is disabled (open) until the
 * WORKER_TOKEN secret exists; once set, requests must present
 * `Authorization: Bearer <WORKER_TOKEN>`.
 */
function isAuthorized(request, env) {
  const expected = env.WORKER_TOKEN;
  if (!expected) {
    return true;
  }
  const header = request.headers.get('Authorization') || '';
  return header === `Bearer ${expected}`;
}

/**
 * Handle enrichment request
 * Accepts image data and returns AI-generated metadata
 */
async function handleEnrichRequest(request, env, ctx) {
  // The WordPress plugin sends snake_case media_id; accept both spellings.
  const { image, filename, media_id, mediaId = media_id } = await request.json();

  if (!image) {
    return { status: 400, body: JSON.stringify({ error: 'Image data required' }) };
  }

  try {
    // Run AI enrichment pipeline
    const enrichment = await enrichImageWithAI(env, image, filename);

    // Cache the enrichment result
    const cacheKey = `enrichment:${filename || mediaId}`;
    await env.METADATA_CACHE.put(cacheKey, JSON.stringify(enrichment), {
      expirationTtl: 86400 * 30 // 30 days
    });

    return {
      status: 200,
      body: JSON.stringify({
        success: true,
        media_id: mediaId || null,
        filename: filename,
        enrichment: enrichment
      })
    };
  } catch (error) {
    console.error('Enrichment failed:', error);
    return {
      status: 500,
      body: JSON.stringify({ error: 'Enrichment failed', message: error.message })
    };
  }
}

/**
 * Handle upload request
 * Stores file in R2 and optionally triggers enrichment
 */
async function handleUploadRequest(request, env, ctx) {
  const formData = await request.formData();
  const file = formData.get('file');
  const mediaId = formData.get('media_id');
  const enrich = formData.get('enrich') === 'true';

  if (!file) {
    return { status: 400, body: JSON.stringify({ error: 'File required' }) };
  }

  try {
    // Determine file type and path
    const fileType = getFileType(file.type);
    const filePath = `${fileType}/${file.name}`;

    // Store in R2
    await env.R2_BUCKET.put(filePath, file, {
      httpMetadata: {
        contentType: file.type,
      },
      customMetadata: {
        uploaded: new Date().toISOString(),
        media_id: mediaId || 'unknown',
        original_name: file.name
      }
    });

    const response = {
      success: true,
      file_path: filePath,
      enriched: false
    };

    // Trigger AI enrichment if requested
    if (enrich && fileType === 'images') {
      const imageBuffer = await file.arrayBuffer();
      const enrichment = await enrichImageWithAI(env, imageBuffer, file.name);

      // Store enrichment metadata in R2
      const metadataPath = `metadata/${file.name}.json`;
      await env.R2_BUCKET.put(metadataPath, JSON.stringify(enrichment), {
        httpMetadata: { contentType: 'application/json' }
      });

      response.enriched = true;
      response.enrichment = enrichment;
    }

    return { status: 200, body: JSON.stringify(response) };
  } catch (error) {
    console.error('Upload failed:', error);
    return {
      status: 500,
      body: JSON.stringify({ error: 'Upload failed', message: error.message })
    };
  }
}

/**
 * Handle media serving request
 * Serves files from R2 with caching headers
 */
async function handleMediaRequest(request, env, ctx) {
  const url = new URL(request.url);
  const filename = url.pathname.replace('/media/', '');

  // Check cache first
  const cacheKey = `media:${filename}`;
  const cached = await env.METADATA_CACHE.get(cacheKey, { type: 'arrayBuffer' });

  if (cached) {
    return new Response(cached, {
      headers: {
        'Content-Type': getContentType(filename),
        'Cache-Control': 'public, max-age=31536000, immutable',
        'CDN-Cache-Control': 'max-age=31536000'
      }
    });
  }

  // Fetch from R2
  const r2Object = await env.R2_BUCKET.get(filename);

  if (!r2Object) {
    return new Response('Not found', { status: 404 });
  }

  // Cache in KV for faster subsequent requests
  ctx.waitUntil(
    env.METADATA_CACHE.put(cacheKey, r2Object.body, { expirationTtl: 86400 * 7 })
  );

  return new Response(r2Object.body, {
    headers: {
      'Content-Type': r2Object.httpMetadata?.contentType || getContentType(filename),
      'Content-Length': String(r2Object.size),
      'Cache-Control': 'public, max-age=31536000, immutable',
      'CDN-Cache-Control': 'max-age=31536000',
      'ETag': r2Object.etag
    }
  });
}

/**
 * Handle metadata request
 * Returns enrichment metadata for a file
 */
async function handleMetadataRequest(request, env, ctx) {
  const url = new URL(request.url);
  const filename = url.pathname.replace('/metadata/', '');

  // Check cache first
  const cacheKey = `enrichment:${filename}`;
  const cached = await env.METADATA_CACHE.get(cacheKey);

  if (cached) {
    return { status: 200, body: cached };
  }

  // Fetch from R2
  const metadataPath = `metadata/${filename}.json`;
  const r2Object = await env.R2_BUCKET.get(metadataPath);

  if (!r2Object) {
    return {
      status: 404,
      body: JSON.stringify({ error: 'Metadata not found' })
    };
  }

  const metadata = await r2Object.text();

  // Cache the result
  ctx.waitUntil(
    env.METADATA_CACHE.put(cacheKey, metadata, { expirationTtl: 86400 })
  );

  return { status: 200, body: metadata };
}

/**
 * Helper: Determine file type from MIME type
 */
function getFileType(mimeType) {
  if (!mimeType) return 'other';
  if (mimeType.startsWith('image/')) return 'images';
  if (mimeType.startsWith('video/')) return 'videos';
  if (mimeType === 'application/pdf') return 'documents';
  return 'other';
}

/**
 * Helper: Get content type from filename
 */
function getContentType(filename) {
  const ext = filename.split('.').pop().toLowerCase();
  const types = {
    'jpg': 'image/jpeg',
    'jpeg': 'image/jpeg',
    'png': 'image/png',
    'gif': 'image/gif',
    'webp': 'image/webp',
    'avif': 'image/avif',
    'mp4': 'video/mp4',
    'webm': 'video/webm',
    'pdf': 'application/pdf',
    'json': 'application/json'
  };
  return types[ext] || 'application/octet-stream';
}