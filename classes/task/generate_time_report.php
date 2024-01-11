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

use core\message\message;
use core_analytics\user;
use moodle_url;

use pdf;
use PhpOffice\PhpSpreadsheet\Calculation\Logical\Boolean;
use tool_time_report\service\course_service;
use tool_time_report;
use function Complex\sec;

class generate_time_report extends \core\task\adhoc_task
{

    private static $COURSES_CACHE = [];
    public $totaltime = 0;

    /**
     * @param $totaltime
     * @return void
     */
    public function set_total_time($totaltime)
    {
        $this->totaltime = $totaltime;
    }

    /**
     * @return int|mixed
     */
    public function get_total_time()
    {
        return $this->totaltime;
    }

    /**
     * Execute the task.
     */
    public function execute()
    {
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

            $user = $DB->get_record('user', array('id' => $data->userid), '*', MUST_EXIST);
            $results_csv = get_log_records($user->id, $startdate, $enddate);
            $results = get_user_log_records_pdf($data->userid, $startdate, $enddate);
            $csvdata = $this->prepare_results($results_csv);
            $this->generate_pdf($results, $user, $data->requestorid, $data->contextid, $startdate, $enddate, $csvdata, $data->is_detail_enabled);
        }
    }

    /**
     * @param \stdClass $user
     * @return string
     * @throws \coding_exception
     * Insert base header if page break while printing body
     */
    private function set_base_pages_header(\stdClass $user): string
    {
        return '<h3>' . get_string('base_pages_heading_title', 'tool_time_report') . '</h3>
                <div>' . get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>
                <br />
                <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                    <tr style="background-color:darkslategray;color:white;">
                        <td style="text-align: center; vertical-align: middle;">Date</td>
                        <td style="text-align: center; vertical-align: middle;">' . get_string('pages_duration', 'tool_time_report'). '</td>
                    </tr>';

    }

    /**
     * Insert details header if page break while printing body
     * @param $pdf
     * @param $user
     * @return string
     */
    private function set_detail_pages_header(\stdClass $user): string
    {
        return '<h3>' . get_string('detail_pages_heading_title', 'tool_time_report') . '</h3>
                         <div>' . get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>
                         <br />
                         <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                             <tr style="background-color:darkslategray;color:white;">
                                <th style="text-align: center; vertical-align: middle;">' . get_string('detail_pages_heading_category', 'tool_time_report') . '</th>
                             </tr>
                         </table>
                         <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                             <tr style="background-color:lightslategray;color:white;">
                                <th style="text-align: center; vertical-align: middle;">' . get_string('detail_pages_heading_course_name', 'tool_time_report') . '</th>
                             </tr>
                         </table>
                         <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                            <tr>
                                <th style="text-align: center; vertical-align: middle;">' . get_string('pages_duration', 'tool_time_report') . '</th>
                            </tr>
                         </table>';
    }

    /**
     * Init daily activities
     * @param array $records
     * @return array
     */
    private function prepare_daily_activities(array $records): array
    {
        $daily_activity = [];

        foreach ($records as $date => $data) {
            $daily_activity[$date] = ['first_access' => 0, 'last_access' => 0];
            $date_logs = json_decode($data->logs);

            foreach ($date_logs as $log) {
                if (!$daily_activity[$date]['first_access'] || $log->timecreated < $daily_activity[$date]['first_access']) {
                    $daily_activity[$date]['first_access'] = $log->timecreated;
                }

                if (!$daily_activity[$date]['last_access'] || $log->timecreated > $daily_activity[$date]['last_access']) {
                    $daily_activity[$date]['last_access'] = $log->timecreated;
                }
            }
        }

        return $daily_activity;
    }

    /**
     * init courses array that will be used for printing
     * @param $csvdata
     * @return array
     */
    private function prepare_csv_courses(array $csvdata): array
    {
        // Building courses array based on csvdata for efficient sorting
        $csv_courses = [];

        foreach ($csvdata as $csv_record) {
            if (!isset($csv_courses[$csv_record[2][0]])) {
                foreach ($csv_record[2] as $key => $course_data) {
                    if (!isset($csv_courses[$key])) {
                        $csv_courses[$key] = new \stdClass();
                        $csv_courses[$key]->course_time_data = $course_data[0];
                        $csv_courses[$key]->course_id = $course_data[1];
                        $csv_courses[$key]->course_name = $key;
                        $csv_courses[$key]->category_id = $course_data[2];
                        $csv_courses[$key]->category_name = $course_data[3];
                        $csv_courses[$key]->category_sortorder = $course_data[4];
                        $csv_courses[$key]->course_sortorder = $course_data[5];
                    } else {
                        $csv_courses[$key]->course_time_data += $course_data[0];
                    }
                }
            }
        }

        return $csv_courses;
    }

    /**
     * Init categories array that will be used for printing
     * @param $csv_courses
     * @return array
     * @throws \dml_exception
     */
    private function prepare_csv_categories(array $csv_courses): array
    {
        $csv_categories = [];

        // Format array by categories ([category]->courses)
        foreach ($csv_courses as $key => $course) {
            if (!isset($csv_categories[$course->category_id])) {
                $csv_categories[$course->category_id][] = $course->category_id;
                $csv_categories[$course->category_id][] = $course->category_name;
                $csv_categories[$course->category_id][] = $course->category_sortorder;
            }

            $csv_categories[$course->category_id][$key] = $course;
        }

        // sorting categories by their displayed order
        uasort($csv_categories, function ($a, $b) {
            return $a[2] <=> $b[2];
        });

        return $csv_categories;
    }

    /**
     * @param array $records
     * @param array $csvdata
     * @param array $csv_courses
     * @param pdf $pdf
     * @param \stdClass $user
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * Display base table with data
     */
    private function print_base_body(array $records, array $csvdata, array $csv_courses, \pdf $pdf, \stdClass $user): void
    {
        $nb_rows = 15;
        $first_table = ' <h3>' . get_string('base_pages_body_heading', 'tool_time_report') . '</h3>
                        <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                            <tr style="background-color:darkslategray;color:white;">
                                <td style="text-align: center; vertical-align: middle;">Date</td>
                                <td style="text-align: center; vertical-align: middle;">' . get_string('pages_duration', 'tool_time_report') . '</td>
                            </tr>
                        ';

        foreach ($records as $key => $data) {
            $datetime = new \DateTime($data->date . ' 23:59:59.000000');
            $date_logs = json_decode($data->logs);
            $total_duration = '00:00:00';

            foreach ($csvdata as $csv_record) {
                if ($datetime->format('d/m/Y') == $csv_record[0]) {
                    $total_duration = $csv_record[1];
                }
            }

            if (isset($total_duration) && $total_duration != '00:00:00') {
                if ($nb_rows > 39 || ($data == array_key_last($records))) {
                    $first_table .= '</table><br>';
                    $pdf->writeHTMLCell(0, 0, '', '', $first_table, 0, 1, 0);
                    $pdf->AddPage();
                    $first_table = $this->set_base_pages_header($user);
                    $nb_rows = 3;
                }

                $first_table .= ' <tr>
                                        <td style="text-align: center; vertical-align: middle;">' . $datetime->format('d/m/Y') . '</td>
                                        <td style="text-align: center; vertical-align: middle;">' . $total_duration . '   </td>
                                      </tr>';

                $nb_rows += 1;

                foreach ($date_logs as $log) {
                    if (in_array($log->target, ['course', 'course_module'])) {
                        $course_fullname = $this->get_course_fullname($log->courseid);
                        if (!isset($csv_courses[$course_fullname]->first_access)) {
                            $csv_courses[$course_fullname]->first_access = date('d/m/Y à H:i', $log->timecreated);
                        } else {
                            $csv_courses[$course_fullname]->last_access = date('d/m/Y à H:i', $log->timecreated);
                        }
                    }
                }
            }
        }

        $first_table .= '</table><br>';
        $pdf->writeHTMLCell(0, 0, '', '', $first_table, 0, 1, 0);
        $pdf->AddPage();
    }


    /**
     * Print detailled report
     * @param pdf $pdf
     * @param int $nb_row
     * @param array $csv_categories
     * @param bool $is_first
     * @return void
     * @throws \dml_exception
     */
    private
    function print_detailled_body(\pdf $pdf, int $nb_row, array $csv_categories, \stdClass $user): void
    {
        $is_first = true;

        foreach ($csv_categories as $grouped_courses) {
            $sorted_courses = $grouped_courses;
            for ($i = 0; $i < 3; $i++) {
                unset($sorted_courses[$i]);
            }

            // Sorting courses inside sorted categories
            uasort($sorted_courses, function ($a, $b) {
                return $a->course_sortorder <=> $b->course_sortorder;
            });

            // Check if enought space on current page
            if ($nb_row > 36) {
                $nb_row = 6;
                $pdf->AddPage();
                $second_table = $this->set_detail_pages_header($user);
                // Add category heading
                $second_table .= '
                                            <p></p>
                                            <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                            <tr style="background-color:darkslategray;color:white;">
                                                <th style="text-align: center; vertical-align: middle;">' . $grouped_courses[1] . '</th>
                                            </tr>
                                            </table>';
            } else {
                // Add category heading
                $second_table = '
                                            <p></p>
                                            <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                            <tr style="background-color:darkslategray;color:white;">
                                                <th style="text-align: center; vertical-align: middle;">' . $grouped_courses[1] . '</th>
                                            </tr>
                                            </table>';
            }

            $nb_row += 3;

            foreach ($sorted_courses as $course_name => $course) {
                $course_total_duration = self::format_seconds($course->course_time_data);

                if ($course_total_duration != '00:00:00') {
                    // Check if enought space on current page
                    if ($nb_row > 39) {
                        $nb_row = 9;
                        $pdf->AddPage();
                        $second_table .= $this->set_detail_pages_header($user);
                        $second_table .= '
                                            <p></p>
                                            <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                            <tr style="background-color:darkslategray;color:white;">
                                                <th style="text-align: center; vertical-align: middle;">' . $grouped_courses[1] . '</th>
                                            </tr>
                                            </table>';
                    }

                    $second_table .= '      <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                                    <tr style="background-color:lightslategray;color:white;">
                                                        <th style="text-align: center; vertical-align: middle;">' . $course_name . '</th>
                                                    </tr>
                                                </table>
                                                ';

                    $nb_row += 1;
                    $second_table .= '      <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                                                    <tr>
                                                        <td style="text-align: center; vertical-align: middle;">' . $course_total_duration . '</td>
                                                    </tr>
                                                </table>';

                    $nb_row += 1;
                    $pdf->writeHTMLCell(0, 0, '', '', $second_table, 0, 1, 0);

                    if ($is_first) {
                        $is_first = false;
                    };

                    $second_table = '';
                }
            }

            $sorted_courses = [];
        }
    }

    /**
     * Generate pdf report
     * @param $records
     * @param $user
     * @param $requestorid
     * @param $contextid
     * @param $start_time
     * @param $end_time
     * @param $csvdata
     * @param $is_detail_enabled
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private
    function generate_pdf(array|string $records, \stdClass $user, int $requestorid, int $contextid, string $start_time, string $end_time, array $csvdata, bool $is_detail_enabled): void
    {
        global $SITE;

        $idletime = get_config('tool_time_report', 'idletime') / MINSECS;
        $borrowedtime = get_config('tool_time_report', 'borrowedtime') / MINSECS;
        $calculation_rule_text = get_config('tool_time_report', 'calculation_rule_text');
        $calculation_rule_text = str_replace('{i}', "<b>$idletime</b>", $calculation_rule_text);
        $calculation_rule_text = str_replace('{b}', "<b>$borrowedtime</b>", $calculation_rule_text);

        $pdf = new \tool_time_report\PDF();
        $pdf->setPrintHeader(false);
        $pdf->getAliasNumPage();
        $pdf->SetFont('Times', '', 12);
        $pdf->setPrintFooter();
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();

        $user_institution = !isset($user->institution) ? $user->institution : "Non-renseigné";
        $user_department = !isset($user->department) ? $user->department : "Non-renseigné";

        // Write fake header on the is_first page.
        $pdf->writeHTML('<img src="https://static.uness.fr/img/UNESS_logo_200x80.png" width="100px" alt="Logo" />', false, false, true, false, 'R');
        $pdf->writeHTML('<h1>' . get_string('header_user_infos_docname', 'tool_time_report') . '</h1>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_generated', 'tool_time_report', date('d/m/Y H:i')) . '</div><br>');
        $pdf->writeHTML('<br><div>' . get_string('header_user_infos_platform', 'tool_time_report', $SITE->fullname) . '</div>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_email', 'tool_time_report', $user->email) . '</div>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_university', 'tool_time_report', $user_institution) . '</div>');
        $pdf->writeHTML('<div>' . get_string('header_user_infos_speciality', 'tool_time_report', $user_department) . '</div>');
        $pdf->writeHTML(
            '<div>' . get_string('header_user_infos_time', 'tool_time_report', (($start_time) ? date('d/m/Y', $start_time) : 'plus ancien'))
            . ' ' . get_string('header_user_infos_time_to', 'tool_time_report', (($end_time) ? date('d/m/Y', $end_time) : 'plus récent'))
            . ' ' . get_string('header_user_infos_total_time', 'tool_time_report', $this::format_seconds($this->totaltime))
            . '</div><br />'
        );

        $pdf->writeHTML($calculation_rule_text);

        // $records is containing a string when an error occurred.
        if (is_string($records)) {
            $pdf->writeHTML('<div>' . $records . '</div>');
            $pdf->Output(generate_pdf_file_name($user->firstname . ' ' . $user->lastname, date('d/m/Y', $start_time), date('d/m/Y', $end_time)) . '.pdf', 'D');
            return;
        }

        $pdf->writeHTML('<br>');

        // prepare daily spent time.
        $daily_activity = $this->prepare_daily_activities($records);

        if ($is_detail_enabled) {
            $csv_courses = $this->prepare_csv_courses($csvdata);
            $csv_categories = $this->prepare_csv_categories($csv_courses);
        }

        $this->print_base_body($records, array_merge($csvdata, $csvdata), $csv_courses ?? [], $pdf, $user);

        if ($is_detail_enabled) {
            $nb_row = 3;
            $second_table_heading = '<h3>' . get_string('detail_pages_heading_title', 'tool_time_report') . '</h3>
                             <div>' . get_string('header_user_infos_user', 'tool_time_report', $user->firstname . ' ' . $user->lastname) . '</div>
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

            $pdf->writeHTMLCell(0, 0, '', '', $second_table_heading, 0, 1, 0);
            $this->print_detailled_body($pdf, $nb_row, $csv_categories, $user);
        }

        if (empty($records)) {
            $pdf->writeHTML('<div><b>' . get_string('pages_no_activity', 'tool_time_report') . '</b></div>');
        }

        $filename = generate_pdf_file_name($user->firstname . ' ' . $user->lastname, date('d/m/Y', $start_time), date('d/m/Y', $end_time));
        $returnstr = $pdf->Output($filename . '.pdf', 'S');

        $this->write_new_file($returnstr, $contextid, $filename, $user, $requestorid);
    }


    /**
     * Return course fullname from cache
     * @param int $course_id
     * @return string
     * @throws \dml_exception
     */
    private
    function get_course_fullname(int $course_id): string
    {
        return self::$COURSES_CACHE[$course_id] ?? self::load_course_fullname($course_id);
    }

    /**
     * Put in cache course fullname
     * @param int $course_id
     * @return mixed|string
     * @throws \dml_exception
     */
    private
    static function load_course_fullname(int $course_id): string
    {
        global $DB;
        $fullname = $DB->get_field('course', 'fullname', ['id' => $course_id]);
        self::$COURSES_CACHE[$course_id] = ($fullname) ? $fullname : get_string('unreachable_course', 'tool_time_report');
        return self::$COURSES_CACHE[$course_id];
    }

    /**
     * @param int $seconds
     * @return string
     */
    private
    static function format_seconds(int $seconds)
    {
        $hours = 0;
        $milliseconds = str_replace('0.', '', $seconds - floor($seconds));

        if ($seconds >= 3600) {
            $hours = floor($seconds / 3600);
        }

        $seconds = $seconds % 3600;

        return str_pad($hours, 2, '0', STR_PAD_LEFT)
            . date(':i:s', $seconds)
            . ($milliseconds ? $milliseconds : '');
    }

    /**
     * Main calculations method
     * @param array $data
     * @return string|array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private
    function prepare_results(array $data): string|array
    {
        if (!array_values($data)) {
            return '<h5>' . get_string('no_results_found', 'tool_time_report') . '</h5>';
        }

        $idletime = get_config('tool_time_report', 'idletime') / MINSECS;
        $borrowedtime = get_config('tool_time_report', 'borrowedtime') * 1;
        $currentday = array_values($data)[0];
        $timefortheday = 0;
        $i = 0;
        $length = count($data);
        $timefortheresource = 0;
        $ressources = [];

        $out = array();
        $totaltime = 0;
        $is_day_last_iteration = false;

        for ($i = 0; $i < $length; $i++) {
            $item = array_values($data)[$i];
            $nextval = self::get_nextval($data, $i);
            $current_resource_fullname = $item->fullname;

            // If the item log time is different than the current day time, we move forward.
            if ($item->logtimecreated !== $currentday->logtimecreated) {
                $currentday = $item;
                $timefortheday = 0;
                $timefortheresource = 0;
                $ressources = [];
            }

            // If ressource not existing for th day, create it
            if (!isset($ressources[$current_resource_fullname])) {
                $ressources[$current_resource_fullname] = [
                    $timefortheresource,
                    $item->courseid,
                    $item->category,
                    $item->name,
                    $item->category_sortorder,
                    $item->course_sortorder
                ];
            } else if ($timefortheresource === 0) {
                $timefortheresource = $ressources[$current_resource_fullname][0];
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
                $ressources[$current_resource_fullname][0] = $timefortheresource + $borrowedtime;
                $timefortheresource = 0;
                $is_day_last_iteration = true;
            }

            if (($current_resource_fullname !== $nextval->fullname) && !$is_day_last_iteration) {
                if ($nextval->logtimecreated != $currentday->logtimecreated) {
                    $ressources[$current_resource_fullname][0] = $timefortheresource + $borrowedtime;
                } else {
                    $ressources[$current_resource_fullname][0] = $timefortheresource;
                }

                $timefortheresource = 0;
            }

            if (($timefortheday > 0 && isset($nextval) && $nextval->logtimecreated != $currentday->logtimecreated)
                || ($timefortheday > 0 && $nextval == $item)) {
                $totaltime = $totaltime + $timefortheday;

                $out = self::push_result($out, $item->timecreated, $timefortheday, $ressources, $item->courseid);
                $is_day_last_iteration = false;
            }
        }

        $this->set_total_time($totaltime);
        return $out;
    }

    /**
     * Get the next item of the array of report results.
     * @param array $data
     * @param int $iteration
     * @return \stdClass
     */
    private
    static function get_nextval(array $data, int $iteration): \stdClass
    {
        $item = array_values($data)[$iteration];

        if (!isset(array_values($data)[$iteration + 1])) {
            return $item;
        }

        return array_values($data)[$iteration + 1];
    }

    /**
     * @param array $items
     * @param int $itemtimecreated
     * @param int $timefortheday
     * @param array $resources
     * @param int $course_id
     * @return array
     */
    private
    static function push_result(array $items, int $itemtimecreated, int $timefortheday, array $resources, int $course_id): array
    {
        $date = date('d/m/Y', $itemtimecreated);
        $seconds = self::format_seconds($timefortheday);
        $items[] = array($date, $seconds, $resources, $course_id);
        return $items;
    }

    /**
     * Write new pdf to the plugin moodle storage area
     * @param $content
     * @param $contextid
     * @param $filename
     * @param $user
     * @param $requestorid
     * @return bool|\stored_file
     * @throws \file_exception
     * @throws \stored_file_creation_exception
     */
    private
    function write_new_file(string $content, int $contextid, string $filename, \stdClass $user, int $requestorid): bool|\stored_file{
        global $CFG;

        $fs = get_file_storage();
        $fileinfo = array(
            'contextid' => $contextid,
            'component' => 'tool_time_report',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $user->id
        );

        $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
            $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);

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
     * @param \stdClass $user
     * @param string $path
     * @param string $filename
     * @param $file
     * @param int $requestorid
     * @return void
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public
    function generate_message(\stdClass $user, string $path, string $filename, $file, int $requestorid): void
    {
        $fullname = fullname($user);
        $messagehtml = "<p>" . get_string('download', 'core') . " : ";
        $messagehtml .= "<a href=\"$path\" download><i class=\"fa fa-download\"></i>$filename</a></p>";
        $contexturl = new moodle_url('/admin/tool/time_report/view.php', array('userid' => $user->id));

        $message = new message();
        $message->component = 'tool_time_report';
        $message->name = 'reportcreation';
        $message->userfrom = \core_user::get_noreply_user();
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
