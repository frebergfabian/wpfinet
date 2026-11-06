<?php
/** wp eval-file tests/js/ssr-render.php <guide-data.json> [index] – prints GuideMarkup::render() for the JSON guide data. */
$data = json_decode( (string) file_get_contents( $args[0] ), true );
if ( isset( $data[0] ) ) { $data = $data[ (int) ( $args[1] ?? 0 ) ]; }
echo \FinetTroubleshoot\Frontend\GuideMarkup::render( $data );
