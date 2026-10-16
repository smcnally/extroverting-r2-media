/**
 * AI Enrichment Module
 * 
 * Uses Cloudflare Workers AI to generate:
 * - Image descriptions
 * - Image titles
 * - Object detection tags
 * - Category classifications
 */

/**
 * Enrich an image with AI-generated metadata
 * 
 * @param {Object} env - Cloudflare Worker environment (contains AI binding)
 * @param {ArrayBuffer} imageBuffer - The image data
 * @param {string} filename - Original filename
 * @returns {Object} Enrichment result with description, title, tags, categories
 */
export async function enrichImageWithAI(env, imageBuffer, filename) {
  const results = {
    filename: filename,
    processed_at: new Date().toISOString(),
    objects: [],
    tags: [],
    description: '',
    title: '',
    categories: [],
    alt_text: ''
  };

  try {
    // Step 1: Object Detection using DETR model
    const objects = await detectObjects(env, imageBuffer);
    results.objects = objects;
    results.tags = objects.map(obj => obj.label);

    // Step 2: Generate description and title using Llama
    const { description, title, categories } = await generateDescriptionAndTitle(
      env, 
      objects, 
      filename
    );
    results.description = description;
    results.title = title;
    results.categories = categories;

    // Step 3: Generate accessibility alt text
    results.alt_text = generateAltText(description, objects);

  } catch (error) {
    console.error('AI enrichment error:', error);
    // Return partial results if available
    results.error = error.message;
  }

  return results;
}

/**
 * Detect objects in an image using DETR ResNet-50
 */
async function detectObjects(env, imageBuffer) {
  try {
    const result = await env.AI.run('@cf/facebook/detr-resnet-50', {
      image: imageBuffer
    });

    // Normalize the two response shapes the model has shipped over time:
    //   { predictions: [{ label, score, bbox }] } or
    //   { labels: [...], scores: [...], boxes: [[...] ] }
    let detections = [];
    if (Array.isArray(result?.predictions)) {
      detections = result.predictions.map((p) => ({
        label: p.label ?? p.class ?? 'unknown',
        score: p.score ?? p.confidence ?? 0,
        bounding_box: p.bbox ?? p.box ?? null
      }));
    } else if (Array.isArray(result?.labels) && Array.isArray(result?.scores)) {
      detections = result.labels.map((label, i) => ({
        label: label,
        score: result.scores[i] ?? 0,
        bounding_box: result.boxes?.[i] ?? null
      }));
    }

    // Filter low-confidence detections, sort by confidence, take top 10
    return detections
      .filter((obj) => obj.score > 0.3)
      .sort((a, b) => b.score - a.score)
      .slice(0, 10);

  } catch (error) {
    console.error('Object detection failed:', error);
    return [];
  }
}

/**
 * Generate image description and title using Llama 3
 */
async function generateDescriptionAndTitle(env, objects, filename) {
  try {
    const objectList = objects.map(obj => obj.label).join(', ');
    
    const prompt = `You are an AI assistant that analyzes images and generates metadata for a media library.

Based on the following image analysis, generate:
1. A detailed description (1-2 sentences)
2. A concise title (3-7 words)
3. Category tags (3-5 comma-separated categories)

Image objects detected: ${objectList || 'none'}
Filename: ${filename}

Respond in JSON format:
{
  "description": "A detailed description of the image...",
  "title": "Concise Title",
  "categories": ["category1", "category2", "category3"]
}`;

    const result = await env.AI.run('@cf/meta/llama-3-8b-instruct', {
      prompt: prompt,
      max_tokens: 256,
      temperature: 0.7
    });

    // Parse the JSON response
    let responseText = result.response || result;
    
    // Try to extract JSON from the response
    const jsonMatch = responseText.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      const parsed = JSON.parse(jsonMatch[0]);
      return {
        description: parsed.description || '',
        title: parsed.title || '',
        categories: parsed.categories || []
      };
    }

    // Fallback: return raw response as description
    return {
      description: responseText.substring(0, 200),
      title: filename.replace(/\.[^/.]+$/, ''),
      categories: objects.slice(0, 3).map(obj => obj.label)
    };

  } catch (error) {
    console.error('Description generation failed:', error);
    return {
      description: `Image: ${filename}`,
      title: filename.replace(/\.[^/.]+$/, ''),
      categories: objects.slice(0, 3).map(obj => obj.label)
    };
  }
}

/**
 * Generate accessibility alt text from description and objects
 */
function generateAltText(description, objects) {
  // Create a concise alt text focusing on the main subject
  const mainObject = objects.length > 0 ? objects[0].label : 'image';
  
  // If description is short enough, use it directly
  if (description.length <= 125) {
    return description;
  }

  // Otherwise, create a concise alt text
  return `Image showing ${mainObject}`;
}

/**
 * Batch enrich multiple images
 * 
 * @param {Object} env - Cloudflare Worker environment
 * @param {Array} images - Array of {imageBuffer, filename} objects
 * @returns {Array} Array of enrichment results
 */
export async function batchEnrichImages(env, images) {
  const results = [];
  
  for (const { imageBuffer, filename } of images) {
    try {
      const enrichment = await enrichImageWithAI(env, imageBuffer, filename);
      results.push(enrichment);
    } catch (error) {
      console.error(`Failed to enrich ${filename}:`, error);
      results.push({
        filename: filename,
        error: error.message,
        processed_at: new Date().toISOString()
      });
    }
  }

  return results;
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
    
    return result.embedding || [];
  } catch (error) {
    console.error('Embedding generation failed:', error);
    return [];
  }
}
