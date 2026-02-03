<?php

/**
 * RUN THIS ONCE to create minimal font files
 * Access at: http://localhost/flutter_backend/create_fonts.php
 */

$fontDir = __DIR__ . '/fpdf/font/';

// Create font directory
if (!file_exists($fontDir)) {
    mkdir($fontDir, 0777, true);
    echo "Created font directory<br>";
}

// Minimal Arial font definitions
$fonts = [
    'arial.php' => '<?php $type="Core";$name="Helvetica";$up=-100;$ut=50;$cw=array(chr(0)=>278,chr(1)=>278);?>',
    'arialb.php' => '<?php $type="Core";$name="Helvetica-Bold";$up=-100;$ut=50;$cw=array(chr(0)=>278,chr(1)=>278);?>',
    'ariali.php' => '<?php $type="Core";$name="Helvetica-Oblique";$up=-100;$ut=50;$cw=array(chr(0)=>278,chr(1)=>278);?>',
    'arialbi.php' => '<?php $type="Core";$name="Helvetica-BoldOblique";$up=-100;$ut=50;$cw=array(chr(0)=>278,chr(1)=>278);?>',
];

foreach ($fonts as $filename => $content) {
    $filepath = $fontDir . $filename;
    file_put_contents($filepath, $content);
    echo "Created: $filename<br>";
}

echo "<br><strong>Done! Font files created.</strong><br>";
echo "<a href='generate_certificate.php?action=test'>Test Certificate System</a>";
