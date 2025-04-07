<?php

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $user_id
 * @param int $start_time
 * @param int $end_time
 * @return string|array
 * @throws dml_exception
 */
function get_user_log_records_pdf(int $user_id, int $start_time = 0, int $end_time = 0): string|array {
    global $DB;
    $logstore_name = get_enabled_logstore_name_pdf();

    switch ($logstore_name) {
        case 'logstore_database':
            $dbtable = get_config('logstore_database', 'dbtable');
            $dbdriver = get_config('logstore_database', 'dbdriver');
            list($dblibrary, $dbtype) = explode('/', $dbdriver);

            if (!$db = \moodle_database::get_driver_instance($dbtype, $dblibrary, true)) {
                return 'Cette fonctionnalité est indisponible. (UNKNOWN_DRIVER)';
            }

            $dboptions = [];
            $dboptions['dbpersist'] = get_config('logstore_database', 'dbpersist');
            $dboptions['dbsocket'] = get_config('logstore_database', 'dbsocket');
            $dboptions['dbport'] = get_config('logstore_database', 'dbport');
            $dboptions['dbschema'] = get_config('logstore_database', 'dbschema');
            $dboptions['dbcollation'] = get_config('logstore_database', 'dbcollation');
            $dboptions['dbhandlesoptions'] = get_config('logstore_database', 'dbhandlesoptions');

            try {
                $db->connect(
                    get_config('logstore_database', 'dbhost'),
                    get_config('logstore_database', 'dbuser'),
                    get_config('logstore_database', 'dbpass'),
                    get_config('logstore_database', 'dbname'),
                    false,
                    $dboptions
                );

                $selected_db = $db;
            } catch (\moodle_exception $e) {
                return 'Cette fonctionnalité est indisponible. (LOGS_ACCESS_2)';
            }

            break;
        case 'logstore_standard':
            $manager = new \tool_log\log\manager();
            $store = new \logstore_standard\log\store($manager);
            $dbtable = '{' . $store->get_internal_log_table_name() . '}';
            $selected_db = $DB;
            break;
        default:
            // Not supported.
            return 'Cette fonctionnalité est indisponible. (LOGS_ACCESS_3)';
    }

    $sql = "
        SELECT TO_TIMESTAMP(timecreated)::date as date, json_agg(json_build_object(
            'timecreated', timecreated, 'action', action, 'target', target, 'courseid', courseid) ORDER BY timecreated
        ) as logs
        FROM $dbtable
        WHERE userid = ? AND courseid <> 1
    ";

    // Check period.
    if ($start_time && $end_time) {
        $sql .= " AND timecreated BETWEEN $start_time AND $end_time";
    } elseif ($start_time) {
        $sql .= " AND timecreated >= $start_time";
    } elseif ($end_time) {
        $sql .= " AND timecreated <= $end_time";
    }

    $sql .= ' GROUP BY date';

    return $selected_db->get_records_sql($sql, [$user_id]);
}

/**
 * Generates the filename.
 * By: Pierre Duverneix
 * @param $username
 * @param $start_date
 * @param $end_date
 * @return string
 * @throws coding_exception
 */
function generate_pdf_file_name(string $username, string $start_time, string $end_time): string {
    if (!$username) throw new \coding_exception('Missing username');

    $start_t = str_replace('/', '-', $start_time);
    $end_t = str_replace('/', '-', $end_time);

    $file_name = 'justificatif-activite_' . to_snake_case_pdf($username) . '_';
    $file_name .= ($start_time) ? $start_t . '_' : 'earlier-';
    $file_name .= ($end_time) ? $end_t : 'latest';

    return $file_name;
}

/**
 * Generates a snake cased username.
 * By: Pierre Duverneix
 * @param  string $str
 * @param  string $glue (optional)
 * @return string
 */
function to_snake_case_pdf(string $str, string $glue = '_'): string {
    $str = preg_replace('/\s+/', '', $str);
    return ltrim(
        preg_replace_callback('/[A-Z]/', function ($matches) use ($glue) {
            return $glue . strtolower($matches[0]);
        }, $str), $glue
    );
}

/**
 * Retrives the files of existing reports
 *
 * @return Array of moodle_url
 */
function get_reports_files(int $contextid, int $userid): array {
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
function get_reports_urls(int $contextid, int $userid): array {
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


/**
 * Returns a 'd-m-Y' date from Javascript timestamp format.
 *
 * @return String
 */
function generate_date_from_jstimestamp($timestamp) {
    return date('d-m-Y', $timestamp / 1000);
}
