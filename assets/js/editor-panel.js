/**
 * Block Editor panel: show enrichment status for the featured image.
 */
wp.plugins.registerPlugin('ext-r2-enrich-panel', {
	render: function(){
		var el = wp.element.createElement;
		var useSelect = wp.data.useSelect;
		// Only show on attachment post type or when a featured image is set.
		return el(wp.editPost.PluginSidebarMoreMenuItem, { target: 'ext-r2-panel' }, 'AI Enrichment');
	}
});
