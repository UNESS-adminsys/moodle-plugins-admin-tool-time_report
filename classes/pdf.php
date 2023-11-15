<?php

namespace tool_time_report;

require_once($CFG->libdir . '/pdflib.php');

class PDF extends \pdf {

    // Page footer
    function Footer() {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
         // Page number
        $this->Cell(0, 10, 'Page ' . $this->PageNo() , 0, 0, 'C');
    }
}
