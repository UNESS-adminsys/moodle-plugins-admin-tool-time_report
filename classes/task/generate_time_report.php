<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Time Report tool task class.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_time_report\task;

require_once(dirname(__FILE__) . '/../../../../../config.php');
require_once(dirname(__FILE__) . '/../../locallib.php');
require_once(dirname(__FILE__) . '/../../lib.php');
require_once($CFG->libdir . '/pdflib.php');
require_once(dirname(__FILE__) . '/../pdf.php');

require_login();

use coding_exception;
use core\message\message;
use core\task\adhoc_task;
use core_user;
use DateTime;
use dml_exception;
use Exception;
use file_exception;
use moodle_exception;
use moodle_url;

use pdf;
use stdClass;
use stored_file;
use stored_file_creation_exception;
use Throwable;
use function Complex\sec;

class generate_time_report extends adhoc_task {
    private static $coursescache = [];
    public $totaltime = 0;

    /**
     * @param $totaltime
     *
     * @return void
     */
    public function set_total_time($totaltime) {
        $this->totaltime = $totaltime;
    }

    /**
     * @return int|mixed
     */
    public function get_total_time() {
        return $this->totaltime;
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (isset($data)) {
            // Check the dates.
            if (!isset($data->start)) {
                $data->start = time() * 1000;
            }

            if (!isset($data->end)) {
                $data->end = time() * 1000;
            }

            // Convert Javascript timestamp to PHP.
            $startdate = $data->start / 1000;
            $enddate = $data->end / 1000;

            $user = $DB->get_record('user', ['id' => $data->userid], '*', MUST_EXIST);
            $resultscsv = get_log_records($user->id, $startdate, $enddate);
            $results = get_user_log_records_pdf($data->userid, $startdate, $enddate);
            $csvdata = $this->prepare_results($resultscsv);
            $this->generate_pdf(
                $results,
                $user,
                $data->requestorid,
                $data->contextid,
                $startdate,
                $enddate,
                $csvdata,
                $data->is_detail_enabled
            );
        }
    }

    /**
     * @param stdClass $user
     *
     * @return string
     * @throws coding_exception
     * Insert base header if page break while printing body
     */
    private function set_base_pages_header(stdClass $user): string {
        return '<h3>' . get_string('base_pages_heading_title', 'tool_time_report') . '</h3>
                <div>' . get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>
                <br />
                <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                    <tr style="background-color:darkslategray;color:white;">
                        <td style="text-align: center; vertical-align: middle;">Date</td>
                        <td style="text-align: center; vertical-align: middle;">' .
            get_string('pages_duration', 'tool_time_report') . '</td>
                    </tr>';
    }

    /**
     * Insert details header if page break while printing body
     *
     * @param $pdf
     * @param $user
     *
     * @return string
     */
    private function set_detail_pages_header(stdClass $user): string {
        return '<h3>' . get_string('detail_pages_heading_title', 'tool_time_report') . '</h3>
                         <div>' .
            get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>
                         <br />
                         <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                             <tr style="background-color:darkslategray;color:white;">
                                <th style="text-align: center; vertical-align: middle;">' .
            get_string('detail_pages_heading_category', 'tool_time_report') . '</th>
                             </tr>
                         </table>
                         <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                             <tr style="background-color:lightslategray;color:white;">
                                <th style="text-align: center; vertical-align: middle;">' .
            get_string('detail_pages_heading_course_name', 'tool_time_report') . '</th>
                             </tr>
                         </table>
                         <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                            <tr>
                                <th style="text-align: center; vertical-align: middle;">' .
            get_string('pages_duration', 'tool_time_report') . '</th>
                            </tr>
                         </table>';
    }

    /**
     * init courses array that will be used for printing
     *
     * @param $csvdata
     *
     * @return array
     */
    private function prepare_csv_courses(array $csvdata): array {
        // Building courses array based on csvdata for efficient sorting
        $csvcourses = [];

        foreach ($csvdata as $csvrecord) {
            foreach ($csvrecord[2] as $key => $coursedata) {
                if (!isset($csvcourses[$key])) {
                    $csvcourses[$key] = new stdClass();
                    $csvcourses[$key]->course_time_data = $coursedata[0];
                    $csvcourses[$key]->course_id = $coursedata[1];
                    $csvcourses[$key]->course_name = $key;
                    $csvcourses[$key]->category_id = $coursedata[2];
                    $csvcourses[$key]->category_name = $coursedata[3];
                    $csvcourses[$key]->category_sortorder = $coursedata[4];
                    $csvcourses[$key]->course_sortorder = $coursedata[5];
                } else {
                    $csvcourses[$key]->course_time_data += $coursedata[0];
                }
            }
        }

        return $csvcourses;
    }

    /**
     * Init categories array that will be used for printing
     *
     * @param $csv_courses
     *
     * @return array
     * @throws dml_exception
     */
    private function prepare_csv_categories(array $csvcourses): array {
        $csvcategories = [];

        // Format array by categories ([category]->courses)
        foreach ($csvcourses as $key => $course) {
            if (!isset($csvcategories[$course->category_id])) {
                $csvcategories[$course->category_id][] = $course->category_id;
                $csvcategories[$course->category_id][] = $course->category_name;
                $csvcategories[$course->category_id][] = $course->category_sortorder;
            }

            $csvcategories[$course->category_id][$key] = $course;
        }

        // sorting categories by their displayed order
        uasort($csvcategories, function ($a, $b) {
            return $a[2] <=> $b[2];
        });

        return $csvcategories;
    }

    /**
     * @param array    $records
     * @param array    $csvdata
     * @param array    $csv_courses
     * @param pdf      $pdf
     * @param stdClass $user
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * Display base table with data
     */
    private function print_base_body(array $records, array $csvdata, pdf $pdf, stdClass $user): void {
        $nbrows = 15;
        $firsttable = ' <h3>' . get_string('base_pages_body_heading', 'tool_time_report') . '</h3>
                        <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                            <tr style="background-color:darkslategray;color:white;">
                                <td style="text-align: center; vertical-align: middle;">Date</td>
                                <td style="text-align: center; vertical-align: middle;">' .
            get_string('pages_duration', 'tool_time_report') . '</td>
                            </tr>
                        ';

        foreach ($records as $data) {
            $datetime = new DateTime($data->date . ' 23:59:59.000000');
            $totalduration = '00:00:00';

            foreach ($csvdata as $csvrecord) {
                if ($datetime->format('d/m/Y') == $csvrecord[0]) {
                    $totalduration = $csvrecord[1];
                }
            }

            if (isset($totalduration) && $totalduration != '00:00:00') {
                if ($nbrows > 39 || ($data == array_key_last($records))) {
                    $firsttable .= '</table><br>';
                    $pdf->writeHTMLCell(0, 0, '', '', $firsttable, 0, 1, 0);
                    $pdf->AddPage();
                    $firsttable = $this->set_base_pages_header($user);
                    $nbrows = 3;
                }

                $firsttable .= ' <tr>
                                        <td style="text-align: center; vertical-align: middle;">' . $datetime->format('d/m/Y') . '</td>
                                        <td style="text-align: center; vertical-align: middle;">' . $totalduration . '   </td>
                                      </tr>';

                $nbrows += 1;
            }
        }

        $firsttable .= '</table><br>';
        $pdf->writeHTMLCell(0, 0, '', '', $firsttable, 0, 1, 0);
    }

    /**
     * Print detailled report
     *
     * @param pdf   $pdf
     * @param int   $nb_row
     * @param array $csv_categories
     * @param bool  $is_first
     *
     * @return void
     * @throws dml_exception
     */
    private function print_detailled_body(pdf $pdf, int $nbrow, array $csvcategories, stdClass $user): void {
        $isfirst = true;

        foreach ($csvcategories as $groupedcourses) {
            $sortedcourses = $groupedcourses;
            for ($i = 0; $i < 3; $i++) {
                unset($sortedcourses[$i]);
            }

            // Sorting courses inside sorted categories
            uasort($sortedcourses, function ($a, $b) {
                return $a->course_sortorder <=> $b->course_sortorder;
            });

            // Check if enought space on current page
            if ($nbrow > 36) {
                $nbrow = 6;
                $pdf->AddPage();
                $secondtable = $this->set_detail_pages_header($user);
                // Add category heading
                $secondtable .= '
                                            <p></p>
                                            <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                            <tr style="background-color:darkslategray;color:white;">
                                                <th style="text-align: center; vertical-align: middle;">' . $groupedcourses[1] . '</th>
                                            </tr>
                                            </table>';
            } else {
                // Add category heading
                $secondtable = '
                                            <p></p>
                                            <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                            <tr style="background-color:darkslategray;color:white;">
                                                <th style="text-align: center; vertical-align: middle;">' . $groupedcourses[1] . '</th>
                                            </tr>
                                            </table>';
            }

            $nbrow += 3;

            foreach ($sortedcourses as $coursename => $course) {
                $coursetotalduration = self::format_seconds($course->course_time_data);

                if ($coursetotalduration != '00:00:00') {
                    // Check if enought space on current page
                    if ($nbrow > 39) {
                        $nbrow = 9;
                        $pdf->AddPage();
                        $secondtable .= $this->set_detail_pages_header($user);
                        $secondtable .= '
                                            <p></p>
                                            <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                            <tr style="background-color:darkslategray;color:white;">
                                                <th style="text-align: center; vertical-align: middle;">' . $groupedcourses[1] . '</th>
                                            </tr>
                                            </table>';
                    }

                    $secondtable .= '      <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                                    <tr style="background-color:lightslategray;color:white;">
                                                        <th style="text-align: center; vertical-align: middle;">' . $coursename . '</th>
                                                    </tr>
                                                </table>
                                                ';

                    $nbrow += 1;
                    $secondtable .= '      <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                                    <tr>
                                                        <td style="text-align: center; vertical-align: middle;">' .
                        $coursetotalduration . '</td>
                                                    </tr>
                                                </table>';

                    $nbrow += 1;
                    $pdf->writeHTMLCell(0, 0, '', '', $secondtable, 0, 1, 0);

                    if ($isfirst) {
                        $isfirst = false;
                    }

                    $secondtable = '';
                }
            }

            $sortedcourses = [];
        }
    }

    /**
     * Generate pdf report
     *
     * @param $records
     * @param $user
     * @param $requestorid
     * @param $contextid
     * @param $start_time
     * @param $end_time
     * @param $csvdata
     * @param $is_detail_enabled
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    private function generate_pdf(
        array|string $records,
        stdClass $user,
        int $requestorid,
        int $contextid,
        string $starttime,
        string $endtime,
        array|string $csvdata,
        bool $isdetailenabled
    ): void {
        global $SITE, $CFG;

        $idletime = get_config('tool_time_report', 'idletime') / MINSECS;
        $borrowedtime = get_config('tool_time_report', 'borrowedtime') / MINSECS;
        $calculationruletext = get_config('tool_time_report', 'calculation_rule_text');
        $calculationruletext = str_replace('{i}', "<b>$idletime</b>", $calculationruletext);
        $calculationruletext = str_replace('{b}', "<b>$borrowedtime</b>", $calculationruletext);

        $pdf = new \tool_time_report\PDF();
        $pdf->setPrintHeader(false);
        $pdf->getAliasNumPage();
        $pdf->SetFont('Times', '', 12);
        $pdf->setPrintFooter();
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();

        $userinstitution = get_string('university_not_specified', 'tool_time_report');

        if (file_exists($CFG->dirroot . '/admin/tool/coreuness/classes/api/core_request.php')) {
            try {
                $core = new \tool_coreuness\api\core_request();
                $userjson = $core->get_json_from_endpoint(
                    'utilisateur/',
                    ['uness_ids' => $user->username, 'fields' => 'universite_rattachement']
                );

                if (!empty($userjson[0]['universite_rattachement'])) {
                    $userinstitution = $userjson[0]['universite_rattachement'];
                }
            } catch (Exception | Throwable $e) {
            }
        } else if (!empty($user->institution)) {
            $userinstitution = $user->institution;
        }

        $logo = get_config('tool_time_report', 'logo');

        // Write fake header on the is_first page.
        if (!empty($logo) && filter_var($logo, FILTER_VALIDATE_URL)) {
            $pdf->writeHTML('<img src="' . $logo . '" width="100px" alt="Logo"/>', false, false, true, false, 'R');
        }
        $pdf->writeHTML('<h1>' . get_string('header_user_infos_docname', 'tool_time_report') . '</h1>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_generated', 'tool_time_report', date('d/m/Y H:i')) . '</div><br>');
        $pdf->writeHTML('<br><div>' . get_string('header_user_infos_platform', 'tool_time_report', $SITE->fullname) . '</div>');
        $pdf->writeHTML('<div>' .
            get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_email', 'tool_time_report', $user->email) . '</div>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_university', 'tool_time_report', $userinstitution) . '</div>');
        // $pdf->writeHTML('<div>' . get_string('header_user_infos_speciality', 'tool_time_report', $user_department) . '</div>');
        $pdf->writeHTML(
            '<div>' .
            get_string('header_user_infos_time', 'tool_time_report', (($starttime) ? date('d/m/Y', $starttime) : 'plus ancien'))
            . ' ' .
            get_string('header_user_infos_time_to', 'tool_time_report', (($endtime) ? date('d/m/Y', $endtime) : 'plus récent'))
            . ' ' . get_string('header_user_infos_total_time', 'tool_time_report', $this::format_seconds($this->totaltime))
            . '</div><br />'
        );

        $pdf->writeHTML($calculationruletext);

        // $records is containing a string when an error occurred.
        if (is_string($records) || is_string($csvdata)) {
            $pdf->writeHTML('<br>' . str_replace('5', '1', $csvdata));
            $filename = generate_pdf_file_name(
                $user->firstname . ' ' . $user->lastname,
                date('d/m/Y', $starttime),
                date('d/m/Y', $endtime)
            );
            $returnstr = $pdf->Output($filename . '.pdf', 'S');

            $this->write_new_file($returnstr, $contextid, $filename, $user, $requestorid);
            return;
        }

        $pdf->writeHTML('<br>');

        if ($isdetailenabled) {
            $csvcourses = $this->prepare_csv_courses($csvdata);
            $csvcategories = $this->prepare_csv_categories($csvcourses);
        }

        $this->print_base_body($records, array_merge($csvdata, $csvdata), $pdf, $user);

        if ($isdetailenabled) {
            $pdf->AddPage();
            $nbrow = 3;
            $secondtableheading = '<h3>' . get_string('detail_pages_heading_title', 'tool_time_report') . '</h3>
                             <div>' .
                get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>
                             <br />
                             <table cellspacing="0" cellpadding="1" border="1" style="border-color: gray;">
                             <tr style="background-color:darkslategray;color:white;">
                                <th style="text-align: center; vertical-align: middle;">Catégorie</th>
                             </tr>
                             </table>
                             <table cellspacing="0" cellpadding="1" border="1" style="border-color: gray;">
                             <tr style="background-color:lightslategray;color:white;">
                                <th style="text-align: center; vertical-align: middle;">Nom du cours</th> 
                             </tr>
                             </table>
                             <table cellspacing="0" cellpadding="1" border="1" style="border-color: gray;">
                             <tr>
                                <th style="text-align: center; vertical-align: middle;">Durée</th>
                             </tr>
                             </table>'; // <br /> not supported here

            $pdf->writeHTMLCell(0, 0, '', '', $secondtableheading, 0, 1, 0);
            $this->print_detailled_body($pdf, $nbrow, $csvcategories, $user);
        }

        if (empty($records)) {
            $pdf->writeHTML('<div><b>' . get_string('pages_no_activity', 'tool_time_report') . '</b></div>');
        }

        $filename =
            generate_pdf_file_name($user->firstname . ' ' . $user->lastname, date('d/m/Y', $starttime), date('d/m/Y', $endtime));
        $returnstr = $pdf->Output($filename . '.pdf', 'S');

        $this->write_new_file($returnstr, $contextid, $filename, $user, $requestorid);
    }

    /**
     * Return course fullname from cache
     *
     * @param int $course_id
     *
     * @return string
     * @throws dml_exception
     */
    private function get_course_fullname(int $courseid): string {
        return self::$coursescache[$courseid] ?? self::load_course_fullname($courseid);
    }

    /**
     * Put in cache course fullname
     *
     * @param int $course_id
     *
     * @return mixed|string
     * @throws dml_exception
     */
    private static function load_course_fullname(int $courseid): string {
        global $DB;
        $fullname = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        self::$coursescache[$courseid] = ($fullname) ?: get_string('unreachable_course', 'tool_time_report');
        return self::$coursescache[$courseid];
    }

    /**
     * @param int $seconds
     *
     * @return string
     */
    private static function format_seconds(int $seconds) {
        $hours = 0;
        $milliseconds = str_replace('0.', '', $seconds - floor($seconds));

        if ($seconds >= 3600) {
            $hours = floor($seconds / 3600);
        }

        $seconds = $seconds % 3600;

        return str_pad($hours, 2, '0', STR_PAD_LEFT)
            . date(':i:s', $seconds)
            . ($milliseconds ?: '');
    }

    /**
     * Main calculations method
     *
     * @param array $data
     *
     * @return string|array
     * @throws coding_exception
     * @throws dml_exception
     */
    private function prepare_results(array $data): string|array {
        if (!array_values($data)) {
            return '<h5>' . get_string('no_results_found', 'tool_time_report') . '</h5>';
        }

        $idletime = get_config('tool_time_report', 'idletime') / MINSECS;
        $borrowedtime = get_config('tool_time_report', 'borrowedtime') * 1;
        $currentday = array_values($data)[0];
        $timefortheday = 0;
        $length = count($data);
        $timefortheresource = 0;
        $ressources = [];
        $out = [];
        $totaltime = 0;
        $isdaylastiteration = false;

        for ($i = 0; $i < $length; $i++) {
            $item = array_values($data)[$i];
            $nextval = self::get_nextval($data, $i);
            $currentresourcefullname = $item->fullname;

            // If the item log time is different than the current day time, we move forward.
            if ($item->logtimecreated !== $currentday->logtimecreated) {
                $currentday = $item;
                $timefortheday = 0;
                $timefortheresource = 0;
                $ressources = [];
            }

            // If ressource not existing for the day, create it
            if (!isset($ressources[$currentresourcefullname])) {
                $ressources[$currentresourcefullname] = [
                    $timefortheresource,
                    $item->courseid,
                    (isset($item->category)) ? $item->category : null,
                    (isset($item->name)) ? $item->name : null,
                    (isset($item->category_sortorder)) ? $item->category_sortorder : null,
                    (isset($item->course_sortorder)) ? $item->course_sortorder : null,
                ];
            } else if ($timefortheresource === 0) {
                $timefortheresource = $ressources[$currentresourcefullname][0];
            }

            // Last iteration.
            if ($item->id === $nextval->id) {
                $totaltime = $totaltime + $timefortheday;
                $out = self::push_result($out, $item->timecreated, $timefortheday, $ressources, $item->courseid);
                break;
            }

            if (isset($nextval) && $nextval->logtimecreated == $currentday->logtimecreated) {
                $nextvaltimecreated = intval($nextval->timecreated);
                $itemtimecreated = intval($item->timecreated);
                $timedelta = $nextvaltimecreated - $itemtimecreated;

                if (intval($timedelta / MINSECS) > $idletime) {
                    $timefortheday = $timefortheday + $borrowedtime;
                    $timefortheresource = $timefortheresource + $borrowedtime;
                } else {
                    $tmpdaytime = $timefortheday + $nextvaltimecreated - $itemtimecreated;
                    $tmpressourcedaytime = $timefortheresource + $nextvaltimecreated - $itemtimecreated;
                    if ($tmpressourcedaytime >= intval($timefortheresource + $idletime)) {
                        $timefortheresource = $tmpressourcedaytime;
                    }

                    if ($tmpdaytime >= intval($timefortheday + $idletime)) {
                        $timefortheday = $tmpdaytime;
                    }
                }
            } else if ($nextval->logtimecreated != $currentday->logtimecreated) {
                // Last iteration of the day.
                $timefortheday = $timefortheday + $borrowedtime;
                $ressources[$currentresourcefullname][0] = $timefortheresource + $borrowedtime;
                $timefortheresource = 0;
                $isdaylastiteration = true;
            }

            if (($currentresourcefullname !== $nextval->fullname) && !$isdaylastiteration) {
                if ($nextval->logtimecreated != $currentday->logtimecreated) {
                    $ressources[$currentresourcefullname][0] = $timefortheresource + $borrowedtime;
                } else {
                    $ressources[$currentresourcefullname][0] = $timefortheresource;
                }

                $timefortheresource = 0;
            }

            if (
                ($timefortheday > 0 && isset($nextval) && $nextval->logtimecreated != $currentday->logtimecreated)
                || ($timefortheday > 0 && $nextval == $item)
            ) {
                $totaltime = $totaltime + $timefortheday;

                $out = self::push_result($out, $item->timecreated, $timefortheday, $ressources, $item->courseid);
                $isdaylastiteration = false;
            }
        }

        $this->set_total_time($totaltime);
        return $out;
    }

    /**
     * Get the next item of the array of report results.
     *
     * @param array $data
     * @param int   $iteration
     *
     * @return stdClass
     */
    private static function get_nextval(array $data, int $iteration): stdClass {
        $item = array_values($data)[$iteration];

        if (!isset(array_values($data)[$iteration + 1])) {
            return $item;
        }

        return array_values($data)[$iteration + 1];
    }

    /**
     * @param array $items
     * @param int   $itemtimecreated
     * @param int   $timefortheday
     * @param array $resources
     * @param int   $course_id
     *
     * @return array
     */
    private static function push_result(
        array $items,
        int $itemtimecreated,
        int $timefortheday,
        array $resources,
        int $courseid
    ): array {
        $date = date('d/m/Y', $itemtimecreated);
        $seconds = self::format_seconds($timefortheday);
        $items[] = [$date, $seconds, $resources, $courseid];
        return $items;
    }

    /**
     * Write new pdf to the plugin moodle storage area
     *
     * @param $content
     * @param $contextid
     * @param $filename
     * @param $user
     * @param $requestorid
     *
     * @return bool|stored_file
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    private function write_new_file(
        string $content,
        int $contextid,
        string $filename,
        stdClass $user,
        int $requestorid
    ): bool|stored_file {
        global $CFG;

        $fs = get_file_storage();
        $fileinfo = [
            'contextid' => $contextid,
            'component' => 'tool_time_report',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $user->id,
        ];

        $file = $fs->get_file(
            $fileinfo['contextid'],
            $fileinfo['component'],
            $fileinfo['filearea'],
            $fileinfo['itemid'],
            $fileinfo['filepath'],
            $fileinfo['filename']
        );

        if ($file) {
            $file->delete(); // Delete the old file first.
        }

        if ($fs->create_file_from_string($fileinfo, $content)) {
            $path = "$CFG->wwwroot/pluginfile.php/$contextid/tool_time_report/content/0/$filename";
            $this->generate_message($user, $path, $filename, $file, $requestorid);
        }

        return $file;
    }

    /**
     * @param stdClass $user
     * @param string   $path
     * @param string   $filename
     * @param          $file
     * @param int      $requestorid
     *
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function generate_message(stdClass $user, string $path, string $filename, $file, int $requestorid): void {
        $fullname = fullname($user);
        $messagehtml = "<p>" . get_string('download', 'core') . " : ";
        $messagehtml .= "<a href=\"$path\" download><i class=\"fa fa-download\"></i>$filename</a></p>";
        $contexturl = new moodle_url('/admin/tool/time_report/view.php', ['userid' => $user->id]);

        $message = new message();
        $message->component = 'tool_time_report';
        $message->name = 'reportcreation';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $requestorid;
        $message->subject = get_string('messageprovider:reportcreation', 'tool_time_report') . " : " . $fullname;
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessage = html_to_text($messagehtml);
        $message->fullmessagehtml = $messagehtml;
        $message->smallmessage = get_string('messageprovider:report_created', 'tool_time_report');
        $message->notification = 1;
        $message->contexturl = $contexturl;
        $message->contexturlname = get_string('time_report', 'tool_time_report');
        $message->attachment = $file; // Set the file attachment.
        message_send($message);
    }
}
