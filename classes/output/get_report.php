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
 * Time Report tool plugin's renderable class file.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_time_report\output;
defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;
use stdClass;

class get_report implements renderable, templatable {

    public function __construct($requestorid, $userid, $username, $contextid, $reportfiles) {
        $this->requestorid = $requestorid;
        $this->userid = $userid;
        $this->username = $username;
        $this->contextid = $contextid;
        $this->reportfiles = $reportfiles;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param renderer_base $output
     * @return stdClass
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $PAGE;

        $PAGE->requires->js_call_amd('tool_time_report/generate_report', 'generateReport', [
            $this->requestorid,
            $this->userid,
            $this->username,
            $this->contextid,
            $this->reportfiles
        ]);

        return [
            'requestorid' => $this->requestorid,
            'userid' => $this->userid,
            'username' => $this->username,
            'contextid' => $this->contextid,
            'reportfiles' => $this->reportfiles,
            'has_reportfiles' => count($this->reportfiles) > 0 ? true : false,
            'clearingaction' => 'clear_files.php',
            'lang' => $CFG->lang
        ];
    }
}
