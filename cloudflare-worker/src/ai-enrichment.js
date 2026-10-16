/**
 * AI Enrichment Module
 *
 * Uses Cloudflare Workers AI to generate:
 * - Image description
 * - Image title
 * - Tags (concrete objects seen in the image)
 * - Category classifications
 * - Accessibility alt text
 *
 * Architecture note (2026-10): the original DETR object-detection →
 * llama-3-8b chain is gone from the Workers AI catalog. A single
 * vision-language model call now does the whole job — it sees the
 * actual image rather than chaining through detected object labels.
 */

const VISION_MODEL = '@cf/meta/llama-4-scout-17b-16e-instruct';
const VISION_MODEL_FALLBACK = '@cf/meta/llama-3.2-11b-vision-instruct';
const TEXT_MODEL_FALLBACK = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

/**
 * Enrich an image with AI-generated metadata
 *
 * @param {Object} env - Cloudflare Worker environment (contains AI binding)
 * @param {ArrayBuffer|Uint8Array|string} imageInput - Image bytes or base64 string
 * @param {string} filename - Original filename
 * @returns {Object} Enrichment result with description, title, tags, categories
 */
export async function enrichImageWithAI(env, imageInput, filename) {
  // Accept base64 strings (what the WordPress plugin sends) as well as
  // ArrayBuffer/Uint8Array — normalize to a data URI for the vision model.
  let imageBuffer = imageInput;
  if (typeof imageInput === 'string') {
    const binary = atob(imageInput);
    imageBuffer = Uint8Array.from(binary, (c) => c.charCodeAt(0));
  }

  const results = {
    filename: filename,
    processed_at: new Date().toISOString(),
    objects: [],
    tags: [],
    description: '',
    title: '',
    categories: [],
    alt_text: '',
    step_errors: []
  };

  try {
    const mimeType = sniffImageMime(imageBuffer);
    const dataUri = `data:${mimeType};base64,${bytesToBase64(imageBuffer)}`;

    const generated = await generateEnrichment(env, dataUri, filename);
    results.description = generated.description;
    results.title = generated.title;
    results.tags = generated.tags;
    results.objects = generated.tags.map((label) => ({ label: label, source: 'vision' }));
    results.categories = generated.categories;
    results.alt_text = generated.alt_text;

    if (generated.model_used) {
      results.model = generated.model_used;
    }
    if (Array.isArray(generated.step_errors)) {
      results.step_errors.push(...generated.step_errors);
    }
  } catch (error) {
    console.error('AI enrichment error:', error);
    results.step_errors.push('enrichment: ' + error.message);
    results.description = `Image: ${filename}`;
    results.title = fallbackTitle(filename);
  }

  return results;
}

/**
 * One vision-model call generating all enrichment fields.
 */
async function generateEnrichment(env, dataUri, filename) {
  const prompt = [
    'You analyze images for a WordPress media library.',
    'Look at the image and produce metadata as ONLY a JSON object with these keys:',
    '- "description": 1-2 factual sentences describing what the image shows',
    '- "title": a concise title, 3-7 words',
    '- "tags": array of 3-6 concrete nouns/objects visible in the image',
    '- "categories": array of 2-4 broad category names (e.g. "Technology", "Events")',
    '- "alt_text": one concise sentence, max 125 characters, for screen readers',
    'No prose before or after the JSON.'
  ].join(' ');

  const messages = [
    {
      role: 'user',
      content: [
        { type: 'text', text: prompt },
        { type: 'image_url', image_url: dataUri }
      ]
    }
  ];

  const errors = [];

  // Primary: llama-4-scout with OpenAI-style image content part. The image_url
  // must be a dictionary ({ url }), not a bare string.
  try {
    const result = await env.AI.run(VISION_MODEL, {
      messages: [
        {
          role: 'system',
          content: 'You are a metadata extraction service. You reply with exactly one JSON object and nothing else.'
        },
        {
          role: 'user',
          content: [
            { type: 'text', text: prompt },
            { type: 'image_url', image_url: { url: dataUri } }
          ]
        }
      ],
      max_tokens: 600,
      temperature: 0.2
    });

    return buildEnrichmentFromResponse(result, filename, VISION_MODEL);
  } catch (error) {
    console.error(`Vision model ${VISION_MODEL} failed:`, error);
    errors.push(`${VISION_MODEL}: ${error.message}`);
  }

  // Fallback: llama-3.2-11b-vision requires a one-time license "agree" prompt.
  try {
    await env.AI.run(VISION_MODEL_FALLBACK, { messages: [{ role: 'user', content: 'agree' }] });
  } catch (error) {
    console.error('Vision license agree failed:', error);
  }
  try {
    const result = await env.AI.run(VISION_MODEL_FALLBACK, {
      messages: [
        {
          role: 'user',
          content: [
            { type: 'text', text: prompt },
            { type: 'image_url', image_url: { url: dataUri } }
          ]
        }
      ],
      max_tokens: 400,
      temperature: 0.4
    });

    return buildEnrichmentFromResponse(result, filename, VISION_MODEL_FALLBACK);
  } catch (error) {
    console.error(`Vision model ${VISION_MODEL_FALLBACK} failed:`, error);
    errors.push(`${VISION_MODEL_FALLBACK}: ${error.message}`);
  }

  // Last resort: text-only description from the filename context.
  try {
    const result = await env.AI.run(TEXT_MODEL_FALLBACK, {
      messages: [
        {
          role: 'user',
          content: prompt.replace('Look at the image and produce', 'No image is available; based on the filename only, produce')
        }
      ],
      max_tokens: 300,
      temperature: 0.4
    });

    return buildEnrichmentFromResponse(result, filename, TEXT_MODEL_FALLBACK);
  } catch (error) {
    console.error(`Text model ${TEXT_MODEL_FALLBACK} failed:`, error);
    errors.push(`${TEXT_MODEL_FALLBACK}: ${error.message}`);
  }

  throw new Error('All models failed: ' + errors.join(' | '));
}

/**
 * Normalize a model response into the enrichment shape.
 */
function buildEnrichmentFromResponse(result, filename, modelUsed) {
  let candidate = typeof result === 'string' ? result : (result.response ?? result.content ?? result);

  // The model output may arrive as a plain string (JSON in prose) or as an
  // already-parsed object (Workers AI can hand back structured responses).
  let parsed = {};
  if (typeof candidate === 'string') {
    parsed = extractJson(candidate);
  } else if (candidate && typeof candidate === 'object') {
    parsed = candidate;
  }

  const out = {
    description: parsed.description || `Image: ${filename}`,
    title: parsed.title || fallbackTitle(filename),
    tags: Array.isArray(parsed.tags) ? parsed.tags.slice(0, 6).map(String) : [],
    categories: Array.isArray(parsed.categories) ? parsed.categories.slice(0, 4).map(String) : [],
    alt_text: parsed.alt_text || '',
    model_used: modelUsed
  };

  if (!parsed.description) {
    // Keep the raw model output for diagnostics when JSON extraction failed.
    const raw = typeof responseText === 'string' ? responseText : JSON.stringify(responseText);
    out.step_errors = ['unparsed model response: ' + String(raw).slice(0, 300)];
  }

  return out;
}

/**
 * Extract the first JSON object from model output.
 */
function extractJson(text) {
  if (typeof text !== 'string') {
    return {};
  }
  const jsonMatch = text.match(/\{[\s\S]*\}/);
  if (jsonMatch) {
    try {
      return JSON.parse(jsonMatch[0]);
    } catch (error) {
      console.error('JSON parse of model output failed:', error);
    }
  }
  return {};
}

/**
 * Sniff the image MIME type from magic bytes (fallback: PNG).
 */
function sniffImageMime(bytes) {
  const b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  if (b.length > 3 && b[0] === 0x89 && b[1] === 0x50) return 'image/png';
  if (b.length > 2 && b[0] === 0xff && b[1] === 0xd8) return 'image/jpeg';
  if (b.length > 11 && b[8] === 0x57 && b[9] === 0x45 && b[10] === 0x42 && b[11] === 0x50) return 'image/webp';
  if (b.length > 2 && b[0] === 0x47 && b[1] === 0x49) return 'image/gif';
  return 'image/png';
}

/**
 * Uint8Array → base64 (no Node Buffer dependency needed).
 */
function bytesToBase64(bytes) {
  let binary = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}

/**
 * Derive a title from a filename.
 */
function fallbackTitle(filename) {
  return String(filename || 'image')
    .replace(/\.[^/.]+$/, '')
    .replace(/[-_]+/g, ' ')
    .trim() || 'image';
}

/**
 * Generate text embeddings for search indexing
 *
 * @param {Object} env - Cloudflare Worker environment
 * @param {string} text - Text to embed (description + tags)
 * @returns {Array} Embedding vector
 */
export async function generateTextEmbedding(env, text) {
  try {
    const result = await env.AI.run('@cf/baai/bge-base-en-v1.5', {
      text: text
    });

    return result.data?.[0] || result.embedding || [];
  } catch (error) {
    console.error('Embedding generation failed:', error);
    return [];
  }
}