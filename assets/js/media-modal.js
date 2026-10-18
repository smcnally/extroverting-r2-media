/**
 * Media Library modal: show AI enrichment badge + one-click re-enrich.
 * Loaded only on upload/attachment screens.
 */
(function($){
	// Runs after the media grid is ready; polls the new REST endpoint.
	function refreshBadges(){
		$('.attachment').each(function(){
			var id = $(this).data('id');
			if(!id) return;
			fetch(window.ExtR2Media.restUrl + 'media/' + id, {
				headers: { 'X-WP-Nonce': window.ExtR2Media.restNonce }
			}).then(r=>r.json()).then(function(data){
				if(data && data.status){
					var $a = $('.attachment[data-id="'+id+'"]');
					if(!$a.find('.ext-r2-badge').length){
						$a.append('<span class="ext-r2-badge" style="position:absolute;bottom:4px;right:4px;background:#16241d;color:#fff;font-size:10px;padding:2px 6px;border-radius:999px;">AI: '+data.status+'</span>');
					}
				}
			}).catch(()=>{});
		});
	}
	$(window).on('load', function(){ setTimeout(refreshBadges, 800); });
})(jQuery);
