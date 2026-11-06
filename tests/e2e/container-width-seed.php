<?php
/** Seeds a page that puts the same guide into many container types (wp eval-file). Prints {"url":…}. */
$doc = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/finet-troubleshoot/examples/finet-troubleshoot-example.json' ), true );
$doc['guides'][0]['external_id'] = 'container-guide';
$doc['guides'][0]['slug']        = 'container-guide';
$r  = ( new FinetTroubleshoot\Import\ImportManager( new FinetTroubleshoot\Validation\FlowValidator() ) )->import_data( $doc, 'copy' )->to_array();
$id = (int) $r['items'][0]['post_id'];
wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
$g     = '[finet_troubleshoot id="' . $id . '"]';
// expect=full: the guide must be exactly min(760, available). expect=fit: it shares space and must stay readable.
$cases = array(
	'block'                     => array( 'full', '<div data-case="block" data-expect="full" data-slot>' . $g . '</div>' ),
	'flex column, flex-start'   => array( 'full', '<div data-case="flex column, flex-start" data-expect="full" data-slot style="display:flex;flex-direction:column;align-items:flex-start"><div>' . $g . '</div></div>' ),
	'flex column, center'       => array( 'full', '<div data-case="flex column, center" data-expect="full" data-slot style="display:flex;flex-direction:column;align-items:center"><div>' . $g . '</div></div>' ),
	'flex row, alone'           => array( 'full', '<div data-case="flex row, alone" data-expect="full" data-slot style="display:flex"><div style="max-width:100%">' . $g . '</div></div>' ),
	'flex row, with sibling'    => array( 'fit', '<div data-case="flex row, with sibling" data-expect="fit" data-slot style="display:flex;gap:20px"><div style="flex:1 1 0;min-width:0">' . $g . '</div><div style="flex:1 1 0">Sidokolumn med text.</div></div>' ),
	'grid auto column'          => array( 'full', '<div data-case="grid auto column" data-expect="full" data-slot style="display:grid;justify-items:start"><div style="max-width:100%">' . $g . '</div></div>' ),
	'inline-block'              => array( 'full', '<div data-case="inline-block" data-expect="full" data-slot><div style="display:inline-block;max-width:100%">' . $g . '</div></div>' ),
	'float'                     => array( 'full', '<div data-case="float" data-expect="full" data-slot style="overflow:hidden"><div style="float:left;max-width:100%">' . $g . '</div></div>' ),
	'table cell'                => array( 'full', '<div data-case="table cell" data-expect="full" data-slot><div style="display:table;max-width:100%"><div style="display:table-cell">' . $g . '</div></div></div>' ),
);
$html = '<!-- wp:html -->' . implode( "\n", array_map( static fn ( $c ) => $c[1], $cases ) ) . '<!-- /wp:html -->';
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Containertest', 'post_name' => 'containertest', 'post_content' => $html ) );
echo wp_json_encode( array( 'url' => get_permalink( $page ) ) );
