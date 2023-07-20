<?php

namespace tool_time_report;

require_once($CFG->libdir . '/pdflib.php');

$userid = $_GET['userid'];

class PDF extends \pdf
{
// Page header
    /*function Header()
    {
        $userid = $_GET['userid'];
        // Logo
        $this->Image('https://static.uness.fr/img/UNESS_logo_200x80.png', 0, 0, 30, 0, '', '', 'R', false, 300, 'R');
        // Move to the right
        $this->Ln(20);
        // Title
        $this->Cell(0, 0, "Rapport d'activité, Temps de connexion". $userid, 0, 0, 'R');
        // Line break
        $this->Ln(20);
    }*/

    // Page footer
    function Footer()
    {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
         // Page number
        $this->Cell(0, 10, 'Page ' . $this->PageNo() , 0, 0, 'C');
    }
}
