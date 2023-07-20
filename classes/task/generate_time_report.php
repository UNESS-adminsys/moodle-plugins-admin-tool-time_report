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
$is_detail_enabled = optional_param('is_detail_enabled', false, PARAM_BOOL);

require_login();

use core\message\message;
use moodle_url;

use pdf;
use tool_useractivityreport\service\course_service;

class generate_time_report extends \core\task\adhoc_task {

    private static $COURSES_CACHE = [];

    public $totaltime = 0;

    public function set_total_time($totaltime) {
        $this->totaltime = $totaltime;
    }

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

            $user = $DB->get_record('user', array('id' => $data->userid), '*', MUST_EXIST);
            $results_csv = get_log_records($user->id, $startdate, $enddate);
            $results = tget_user_log_records($data->userid, $startdate, $enddate);
            $csvdata = $this->prepare_results($user, $results_csv);
            $this->generate_pdf($results, $user, $data->requestorid, $data->contextid, $startdate, $enddate, $csvdata);
            //$this->create_csv($user, $data->requestorid, $csvdata, $data->contextid, $startdate, $enddate);
        }
    }

    private function change_page_pdf ($pdf, $user) {
        $pdf->writeHTML("<h1>Rapport d'activité, Temps de connexion</h1>");
        $pdf->writeHTML('<div>Utilisateur : ' . $user->firstname . ' ' . $user->lastname . '</div>');
    }

    private function generate_pdf($records, $user, $requestorid, $contextid, $start_time, $end_time, $csvdata) {
        global $SITE, $USER, $is_detail_enabled, $DB;
        $pdf = new \tool_time_report\PDF();
        $pdf->setPrintHeader(false);
        //$pdf->setHeaderData('https://static.uness.fr/img/UNESS_logo_200x80.png', 0, "", $user->firstname . ' ' . $user->lastname, array(0,64,255), array(0,64,128));

        $pdf->getAliasNumPage();
        $pdf->SetFont('Times', '', 12);
        $pdf->setPrintFooter();
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->AddPage();

        $pdf_page_no = $pdf->PageNo();
        $user_institution = !isset($user->institution) ? $user->institution : "Non-renseigné";
        $user_department = !isset($user->department) ? $user->department : "Non-renseigné";

        // Write fake header on the first page.
        $pdf->writeHTML('<img src="https://static.uness.fr/img/UNESS_logo_200x80.png" width="100px" alt="Logo" />', false, false, true, false, 'R');
        $pdf->writeHTML("<h1>Rapport d'activité, Temps de connexion</h1>");
        $pdf->writeHTML('<div>Généré le : ' . date('d/m/Y') . '</div><br>');
        $pdf->writeHTML('<br><div>Plateforme : ' . $SITE->fullname . '</div>');
        $pdf->writeHTML('<div>Utilisateur : ' . $user->firstname . ' ' . $user->lastname . '</div>');
        $pdf->writeHTML('<div>Courriel : ' . $user->email . '</div>');
        $pdf->writeHTML('<div>Université : ' . $user_institution . '</div>');
        $pdf->writeHTML('<div>Spécialité : ' . $user_department . '</div>');

        $pdf->writeHTML('<div><b style="color: white">----</b><b>Date</b><b style="color: white">----</b> | <b style="color: white">--</b><b>Durée</b><b style="color: white">-------</b><b>Premier accès à</b><b style="color: white">-------</b><b>Dernier accès à</b></div>');
        $pdf->writeHTML('<div><b>06/07/2023</b> | 00:00:00 | <b style="color: white">--------</b>00:00:00<b style="color: white">--------</b> | <b style="color: white">--------</b>00:00:00<b style="color: white">--------</b></div>');

        $pdf->writeHTML(
            '<div>Période : du '
            . (($start_time) ? date('d/m/Y', $start_time) : 'plus ancien')
            . ' au '
            . (($end_time) ? date('d/m/Y', $end_time) : 'plus récent')
            . ' - Temps de connexion total : ' . end($csvdata)[1] . '</div>'
        );

        // $records is containing a string when an error occurred.
        if (is_string($records)) {
            $pdf->writeHTML('<div>' . $records . '</div>');
            $pdf->Output(tgenerate_file_name($user->firstname . ' ' . $user->lastname, date('d/m/Y', $start_time), date('d/m/Y', $end_time)) . '.pdf', 'D');
            return;
        }

        // Prepare daily spent time.
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

        $csv_courses = [];
        if (true) {
            foreach ($csvdata as $csv_record) {
                if (!isset($csv_courses[$csv_record[2]])) {
                    foreach ($csv_record[2] as $key => $course_data) {
                        if (!isset($csv_courses[$key])) {
                            $csv_courses[$key] = new \stdClass();
                            $csv_courses[$key]->course_time_data = $course_data;
                            $csv_courses[$key]->course_id = $csv_record[3];
                        } else {
                            $csv_courses[$key]->course_time_data += $course_data;
                        }
                    }
                }
            }

            $pdf->writeHTML('<br>');
        }

        // Table 1.
        $first_table = '
                        <h3>Tableau 1</h3>
                        <table cellspacing="0" cellpadding="1" border="1" style="border-color:gray;">
                            <tr style="background-color:blueviolet;color:white;">
                                <td>Date</td>
                                <td>Durée</td>
                                <td>Premier accès à</td>
                                <td>Dernier accès à</td>
                            </tr>
                        ';

        foreach ($records as $date => $data) {
            $datetime = new \DateTime($data->date . ' 23:59:59.000000');
            $date_logs = json_decode($data->logs);
            $total_duration = '00:00:00';

            foreach ($csvdata as $csv_record) {
                if ($datetime->format('d/m/Y') == $csv_record[0]) {
                    $total_duration = $csv_record[1];
                }
            }

            if (!isset($total_duration)) $total_duration = '00:00:00';

            $first_table .= "<tr>
                        <td>$datetime->format('d/m/Y')</td>
                        <td>$total_duration</td>
                        <td>date('H:i', $daily_activity[$date]['first_access'])</td>
                        <td>date('H:i', $daily_activity[$date]['last_access'])</td>
                      </tr>";

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

        $first_table .= '</table>';
        $pdf->writeHTMLCell(0, 0, '', '', $first_table, 0, 1, 0, true, '', true);


        // Table 2
        foreach ($csv_courses as $course_name => $course) {
            $course->category_id = $DB->get_field('course', 'category', ['id' => $course->course_id]);
            $course_total_duration = self::format_seconds($course->course_time_data);

            $pdf->writeHTML('<br><div><b>Catégorie : '.$course->category_id.' - '.$course_name.'</b> - durée cumulée : '.$course_total_duration);
            if ((isset($course->first_access) && isset($course->last_access) && $course->first_access != $course->last_access)) {
                $pdf->writeHTML(' <br>Premier accès à '.$course->first_access.' <br>Dernier accès à '.$course->last_access.'</div>');
            } else {
                $access = (isset($course->first_access)) ? $course->first_access : $course->last_access;
                if (isset($access)) {
                    $pdf->writeHTML(' <br>Premier et dernier accès à ' . $access . '</div>');
                } else {
                    $pdf->writeHTML(" <br>Aucune heure d'accès enregistrée</div>");
                }
            }
        }


        if (empty($records)) {
            $pdf->writeHTML('<div><b>Aucune activité sur cette période.</b></div>');
        }



        $filename = tgenerate_file_name($user->firstname . ' ' . $user->lastname, date('d/m/Y', $start_time), date('d/m/Y', $end_time));
        $returnstr = $pdf->Output($filename . '.pdf', 'S');

        return $this->write_new_file($returnstr, $contextid, $filename, $user, $requestorid);
    }

    private function get_course_fullname($course_id) {
        return self::$COURSES_CACHE[$course_id] ?? self::load_course_fullname($course_id);
    }

    /**
     * Put in cache course fullname
     * @param int $course_id
     * @return mixed|string
     * @throws \dml_exception
     */
    private static function load_course_fullname(int $course_id) {
        global $DB;
        $fullname = $DB->get_field('course', 'fullname', ['id' => $course_id]);
        self::$COURSES_CACHE[$course_id] = ($fullname) ? $fullname : '[ce cours n\'est plus accessible]';
        return self::$COURSES_CACHE[$course_id];
    }

    private static function format_seconds($seconds) {
        $hours = 0;
        $milliseconds = str_replace('0.', '', $seconds - floor( $seconds ));
        if ($seconds >= 3600) {
            $hours = floor($seconds / 3600);
        }
        $seconds = $seconds % 3600;
        return str_pad($hours, 2, '0', STR_PAD_LEFT)
            . date(':i:s', $seconds)
            . ($milliseconds ? $milliseconds : '');
    }

    private function prepare_results($user, $data) {
        if (!array_values($data)) {
            return '<h5>'. get_string('no_results_found', 'tool_time_report') .'</h5>';
        }

        $idletime = get_config('tool_time_report', 'idletime') / MINSECS;
        $borrowedtime = get_config('tool_time_report', 'borrowedtime') * 1;
        $currentday = array_values($data)[0];
        $timefortheday = 0;
        $i = 0;
        $length = count($data);

        $timefortheressource = 0;
        $ressources = [];
        $previous_ressource = 0;
        $previous_item = 0;

        $out = array();
        $totaltime = 0;
        $sent = false;

        for ($i; $i < $length; $i++) {
            $previous_item = array_values($data)[$i - 1];
            $item = array_values($data)[$i];
            $nextval = self::get_nextval($data, $i);
            $current_ressource = $item->fullname;



            // If the item log time is different than the current day time, we move forward.
            if ($item->logtimecreated != $currentday->logtimecreated) {
                $currentday = $item;
                $timefortheday = 0;
                $timefortheressource = 0;
                $ressources = [];
            }

            // If ressource not existing for th day, create it
            if (!isset($ressources[$current_ressource])) {
                $ressources[$current_ressource] = $timefortheressource;
            } else if ($timefortheressource == 0) {
                $timefortheressource = $ressources[$current_ressource];
            }

            // Last iteration.
            if ($item->id == $nextval->id) {
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
                    $timefortheressource = $timefortheressource + $borrowedtime;
                } else {
                    $tmpdaytime = $timefortheday + $nextvaltimecreated - $itemtimecreated;
                    $tmpressourcedaytime = $timefortheressource + $nextvaltimecreated - $itemtimecreated;
                    if ($tmpressourcedaytime >= intval($timefortheressource + $idletime)) {
                        $timefortheressource = $tmpressourcedaytime;
                    }
                    if ($tmpdaytime >= intval($timefortheday + $idletime)) {
                        $timefortheday = $tmpdaytime;
                    }
                }
            } else if ($nextval->logtimecreated != $currentday->logtimecreated) {
                // Last iteration of the day.
                $timefortheday = $timefortheday + $borrowedtime;
                $ressources[$current_ressource] = $timefortheressource + $borrowedtime;
                $timefortheressource = 0;
                $sent = true;
            }

            if (($current_ressource != $nextval->fullname) && !$sent) {
                if ($nextval->logtimecreated != $currentday->logtimecreated) {
                    $ressources[$current_ressource] = $timefortheressource + $borrowedtime;
                } else {
                    $ressources[$current_ressource] = $timefortheressource;
                }
                $timefortheressource = 0;
            }

            if (($timefortheday > 0 && isset($nextval) && $nextval->logtimecreated != $currentday->logtimecreated)
                || ($timefortheday > 0 && $nextval == $item)) {
                $totaltime = $totaltime + $timefortheday;


                /*foreach ($ressources as $res => $ressource) {
                    $ressources[$res] = self::format_seconds($ressource);
                }*/

                $out = self::push_result($out, $item->timecreated, $timefortheday, $ressources, $item->courseid);
                $sent = false;
            }
        }

        $this->set_total_time($totaltime);
        return $out;
    }

    /**
     * Get the next item of the array of report results.
     */
    private static function get_nextval($data, $iteration) {
        $item = array_values($data)[$iteration];
        if (!isset(array_values($data)[$iteration + 1])) {
            return $item;
        }
        return array_values($data)[$iteration + 1];
    }

    private static function push_result($items, $itemtimecreated, $timefortheday, $ressources, $course_id) {
        $date = date('d/m/Y', $itemtimecreated);
        $seconds = self::format_seconds($timefortheday);
        array_push($items, array($date, $seconds, $ressources, $course_id));
        return $items;
    }

    private function create_csv($user, $requestorid, $data, $contextid, $startdate, $enddate) {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');
        require_once(dirname(__FILE__) . '/../../locallib.php');

        $strstartdate = date('d-m-Y', $startdate);
        $strenddate = date('d-m-Y', $enddate);

        $delimiter = \csv_import_reader::get_delimiter('comma');
        $csventries = array(array());
        $csventries[] = array(get_string('name', 'core'), $user->lastname);
        $csventries[] = array(get_string('firstname', 'core'), $user->firstname);
        $csventries[] = array(get_string('email', 'core'), $user->email);
        $csventries[] = array(get_string('period', 'tool_time_report'), $strstartdate . ' - ' . $strenddate);
        $csventries[] = array(get_string('period_total_time', 'tool_time_report'), self::format_seconds($this->get_total_time()));
        $csventries[] = array('Date', get_string('total_duration', 'tool_time_report'));

        $returnstr = '';
        $len = count($data);
        $shift = count($csventries);

        for ($i = 0; $i < $len; $i++) {
            $csventries[$i + $shift] = $data[$i];
        }
        foreach ($csventries as $entry) {
            $returnstr .= '"' . implode('"' . $delimiter . '"', $entry) . '"' . "\n";
        }

        $filename = generate_file_name(fullname($user), $strstartdate, $strenddate);

        return $this->write_new_file($returnstr, $contextid, $filename, $user, $requestorid);
    }

    private function write_new_file($content, $contextid, $filename, $user, $requestorid) {
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

    public function generate_message($user, $path, $filename, $file, $requestorid) {
        $fullname = fullname($user);
        $messagehtml = "<p>" . get_string('download', 'core') . " : ";
        $messagehtml .= "<a href=\"$path\" download><i class=\"fa fa-download\"></i>$filename</a></p>";
        $contexturl = new moodle_url('/admin/tool/time_report/view.php', array('userid' => $user->id));

        $message = new message();
        $message->component         = 'tool_time_report';
        $message->name              = 'reportcreation';
        $message->userfrom          = \core_user::get_noreply_user();
        $message->userto            = $requestorid;
        $message->subject           = get_string('messageprovider:reportcreation', 'tool_time_report'). " : " .$fullname;
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessage       = html_to_text($messagehtml);
        $message->fullmessagehtml   = $messagehtml;
        $message->smallmessage      = get_string('messageprovider:report_created', 'tool_time_report');
        $message->notification      = 1;
        $message->contexturl        = $contexturl;
        $message->contexturlname    = get_string('time_report', 'tool_time_report');
        $message->attachment = $file; // Set the file attachment.
        message_send($message);
    }


}
