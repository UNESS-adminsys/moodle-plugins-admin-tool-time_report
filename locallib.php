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
 * Time Report tool plugin's local lib.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

/**
 * Retrives the files of existing reports
 *
 * @return Array of moodle_url
 */
function get_reports_files($contextid, $userid) {
    global $DB;

    $conditions = array('contextid' => $contextid, 'component' => 'tool_time_report', 'filearea' => 'content', 'userid' => $userid);
    $filerecords = $DB->get_records('files', $conditions);
    return $filerecords;
}

/**
 * Retrives the moodle_url of existing reports
 *
 * @return Array of moodle_url
 */
function get_reports_urls($contextid, $userid) {
    $files = get_reports_files($contextid, $userid);
    $out = array();

    foreach ($files as $file) {
        if ($file->filename != '.') {
            $path = '/' . $file->contextid . '/tool_time_report/content/' . $file->itemid . $file->filepath . $file->filename;
            $url = moodle_url::make_file_url('/pluginfile.php', $path);
            array_push($out, array('url' => $url, 'filename' => $file->filename));
        }
    }

    return $out;
}

/**
 * Generates the filename.
 *
 * @param  string $startdate
 * @param  string $enddate
 * @return string
 */
function generate_file_name($username, $startdate, $enddate) {
    if (!$username) {
        throw new \coding_exception('Missing username');
    }
    return strtolower(get_string('report', 'core'))
            . '__' . to_snake_case($username)
            . '__' . $startdate . '_' . $enddate . '.csv';
}

/**
 * Extracts the ID of the user from the filename.
 *
 * @param  string $filename
 * @return int
 */
function get_user_id_from_filename($filename) {
    $parts = explode('_', $filename);
    if (isset($parts[2])) {
        return intval($parts[2]);
    }
    return false;
}

/**
 * Removes the report files for a given user.
 *
 * @param  string $filename
 * @return int
 */
function remove_reports_files($contextid, $userid) {
    $files = get_reports_files($contextid, $userid);

    foreach ($files as $file) {
        $fs = get_file_storage();
        $file = $fs->get_file($file->contextid, $file->component, $file->filearea,
            $file->itemid, $file->filepath, $file->filename);
        if ($file) {
            $file->delete();
        }
    }
}

/**
 * Generates a snake cased username.
 *
 * @param  string $str
 * @param  string $glue (optional)
 * @return string
 */
function to_snake_case($str, $glue = '_') {
    $str = preg_replace('/\s+/', '', $str);
    return ltrim(
        preg_replace_callback('/[A-Z]/', function ($matches) use ($glue) {
            return $glue . strtolower($matches[0]);
        }, $str), $glue
    );
}

/**
 * Get the log records
 *
 * @param  int $userid
 * @param  string $startdate
 * @param  string $enddate
 * @return Array of objects
 */
function get_log_records($userid, $startdate, $enddate) {
    global $DB;
    $allowedtargets = get_allowed_targets();
    $dbdriver = get_config('tool_time_report', 'dbdriver');
    $logstore_table = get_log_store_table();
    if ($dbdriver == 'native/pgsql') {
        $sql = "SELECT ${logstore_table}.id, ${logstore_table}.timecreated,
                ${logstore_table}.courseid,
                date(TO_TIMESTAMP(${logstore_table}.timecreated)) AS datecreated,
                date(TO_TIMESTAMP(${logstore_table}.timecreated)) AS logtimecreated,
                ${logstore_table}.userid, {user}.email, {course}.fullname
                FROM ${logstore_table}
                INNER JOIN {course} ON ${logstore_table}.courseid = {course}.id
                LEFT OUTER JOIN {user} ON ${logstore_table}.userid = {user}.id
                WHERE ${logstore_table}.userid = ?
                AND (${logstore_table}.timecreated BETWEEN ? AND ?)
                AND ${logstore_table}.courseid != 1 ";

        if (count($allowedtargets) > 0) {
            $targets = "('" . implode("','", $allowedtargets) . "')";
            $sql .= "AND ${logstore_table}.target IN " . $targets;
        }
    } else {
        $sql = "SELECT ${logstore_table}.id, ${logstore_table}.timecreated,
                ${logstore_table}.courseid,
                date_format(from_unixtime(${logstore_table}.timecreated), '%Y%m') AS datecreated,
                date(from_unixtime(${logstore_table}.timecreated)) AS logtimecreated,
                ${logstore_table}.userid, {user}.email, {course}.fullname
                FROM ${logstore_table}
                INNER JOIN {course} ON ${logstore_table}.courseid = {course}.id
                LEFT OUTER JOIN {user} ON ${logstore_table}.userid = {user}.id
                WHERE ${logstore_table}.userid = ?
                AND ${logstore_table}.timecreated BETWEEN ? AND ?
                AND ${logstore_table}.courseid <> 1 ";

        if (count($allowedtargets) > 0) {
            $targets = implode('","', $allowedtargets);
            $sql .= "AND ${logstore_table}.target IN ('" . $targets . "') ";
        }
    }

    $sql .= "ORDER BY ${logstore_table}.timecreated ASC";
    return $DB->get_records_sql($sql, array($userid, $startdate, $enddate));
}

/**
 * Get all the targets names of the logstore_standard_log table
 *
 * @return Array of string
 */
function get_targets() {
    global $DB;
    $logstore_table = get_log_store_table();
    $sql = "SELECT DISTINCT(target) FROM ${logstore_table}";
    $results = $DB->get_records_sql($sql);
    return array_column($results, 'target');
}

/**
 * Get all the selected targets according to the settings
 *
 * @return Array of string
 */
function get_allowed_targets() {
    $allowedtargets = explode (",", get_config('tool_time_report', 'targets'));
    $targets = get_targets();
    $filteredtargets = array_filter(
        $targets,
        function ($key) use ($allowedtargets) {
            if (in_array($key, $allowedtargets)) {
                if ($allowedtargets[0] && $allowedtargets[0] == "") {
                    return false;
                }
                return true;
            }
        },
        ARRAY_FILTER_USE_KEY
    );
    return $filteredtargets;
}

/**
 * Returns a 'd-m-Y' date from Javascript timestamp format.
 *
 * @return String
 */
function generate_date_from_jstimestamp($timestamp) {
    return date('d-m-Y', $timestamp / 1000);
}
function get_log_store_table($default_table = null): string {
    global $DB;
    $manager = new \tool_log\log\manager();
    $store = new \logstore_standard\log\store($manager);
    if (get_config('tool_time_report', 'use_view_table')) {
        $view_table = get_config('tool_time_report', 'name_view_table');
        $request = "SELECT 'x' FROM moodle.pg_catalog.pg_views WHERE viewname = '{{$view_table}}'";
        return $DB->record_exists_sql($request) ?
                "{{$view_table}}" :
                ($default_table ?: '{' . $store->get_internal_log_table_name() . '}');
    }
    return  $default_table ?: '{' . $store->get_internal_log_table_name() . '}';

}
