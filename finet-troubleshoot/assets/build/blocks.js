/* WPFINET blocks (editor only). Server-rendered via the same shortcodes, so Elementor/shortcode and block output match. */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks || !wp.element) { return; }
	var el = wp.element.createElement, SSR = wp.serverSideRender, be = wp.blockEditor || wp.editor, c = wp.components, __ = wp.i18n ? wp.i18n.__ : function (s) { return s; };
	function preview(name, props) { return SSR ? el(SSR, { block: name, attributes: props.attributes }) : el('p', null, name); }
	wp.blocks.registerBlockType('wpfinet/access-check', {
		apiVersion: 3, title: __('WPFINET adressökning', 'finet-troubleshoot'), icon: 'location', category: 'widgets',
		attributes: { title: { type: 'string', default: '' } },
		edit: function (props) {
			return el('div', be.useBlockProps ? be.useBlockProps() : {}, el(be.InspectorControls, null, el(c.PanelBody, { title: __('Inställningar', 'finet-troubleshoot') }, el(c.TextControl, { label: __('Rubrik', 'finet-troubleshoot'), value: props.attributes.title, onChange: function (v) { props.setAttributes({ title: v }); } }))), preview('wpfinet/access-check', props));
		},
		save: function () { return null; }
	});
	wp.blocks.registerBlockType('wpfinet/instagram-feed', {
		apiVersion: 3, title: __('WPFINET Instagramflöde', 'finet-troubleshoot'), icon: 'instagram', category: 'widgets',
		attributes: { title: { type: 'string', default: '' }, limit: { type: 'number', default: 6 }, columns: { type: 'number', default: 3 }, layout: { type: 'string', default: 'grid' } },
		edit: function (props) {
			var a = props.attributes, set = function (k) { return function (v) { var o = {}; o[k] = v; props.setAttributes(o); }; };
			return el('div', be.useBlockProps ? be.useBlockProps() : {}, el(be.InspectorControls, null, el(c.PanelBody, { title: __('Inställningar', 'finet-troubleshoot') },
				el(c.TextControl, { label: __('Rubrik', 'finet-troubleshoot'), value: a.title, onChange: set('title') }),
				el(c.RangeControl, { label: __('Antal inlägg', 'finet-troubleshoot'), value: a.limit, min: 1, max: 24, onChange: set('limit') }),
				el(c.RangeControl, { label: __('Kolumner', 'finet-troubleshoot'), value: a.columns, min: 1, max: 6, onChange: set('columns') }),
				el(c.SelectControl, { label: __('Layout', 'finet-troubleshoot'), value: a.layout, options: [{ label: 'Rutnät', value: 'grid' }, { label: 'Lista', value: 'list' }], onChange: set('layout') }))), preview('wpfinet/instagram-feed', props));
		},
		save: function () { return null; }
	});
}(window.wp));
