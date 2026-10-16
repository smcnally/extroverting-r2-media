/**
 * Cron Batch Processing Module
 * 
 * Handles scheduled batch processing of media files:
 * - Scan R2 for unenriched images
 * - Run AI enrichment on them
 * - Store metadata back to R2
 */

/**
 * Handle cron-triggered batch processing
 * Runs daily at 2 AM UTC to enrich unprocessed images
 */
export async function handleCronBatchProcessing(env, ctx) {
  console.log('Starting batch enrichment job...');
  
  try {
    // List all images in R2 that don't have metadata yet
    const imagesToEnrich = await findUnenrichedImages(env);
    
    console.log(`Found ${imagesToEnrich.length} images to enrich`);
    
    let enrichedCount = 0;
    let errorCount = 0;
    
    for (const imageKey of imagesToEnrich) {
      try {
        // Get the image from R2
        const imageObject = await env.R2_BUCKET.get(imageKey);
        if (!imageObject) {
          console.warn(`Image not found: ${imageKey}`);
          continue;
        }
        
        const imageBuffer = await imageObject.arrayBuffer();
        const filename = imageKey.split('/').pop();
        
        // Run AI enrichment
        const { enrichImageWithAI } = await import('./ai-enrichment.js');
        const enrichment = await enrichImageWithAI(env, imageBuffer, filename);
        
        // Store metadata in R2
        const metadataKey = `metadata/${filename}.json`;
        await env.R2_BUCKET.put(metadataKey, JSON.stringify(enrichment), {
          httpMetadata: { contentType: 'application/json' },
          customMetadata: {
            source_file: imageKey,
            enriched_at: new Date().toISOString()
          }
        });
        
        enrichedCount++;
        console.log(`Enriched: ${imageKey}`);
        
      } catch (error) {
        errorCount++;
        console.error(`Failed to enrich ${imageKey}:`, error);
      }
      
      // Respect rate limits - small delay between requests
      await sleep(100);
    }
    
    console.log(`Batch enrichment complete: ${enrichedCount} enriched, ${errorCount} errors`);
    
  } catch (error) {
    console.error('Batch processing failed:', error);
  }
}

/**
 * Find images in R2 that don't have metadata files yet
 */
async function findUnenrichedImages(env) {
  const unenriched = [];
  
  // List all objects in the images/ prefix
  const imagesList = await env.R2_BUCKET.list({ prefix: 'images/' });
  
  // Get all metadata files
  const metadataList = await env.R2_BUCKET.list({ prefix: 'metadata/' });
  const metadataFilenames = new Set(
    metadataList.objects.map(obj => obj.key.replace('metadata/', '').replace('.json', ''))
  );
  
  // Find images without metadata
  for (const image of imagesList.objects) {
    const filename = image.key.split('/').pop();
    if (!metadataFilenames.has(filename)) {
      unenriched.push(image.key);
    }
  }
  
  return unenriched;
}

/**
 * Sleep utility
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}
