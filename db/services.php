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
 * Time Report tool plugin's services file.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$functions = [
    'tool_time_report_generate_time_report' => [
        'classname'   => 'tool_time_report\external',
        'methodname'  => 'generate_time_report',
        'classpath'   => '',
        'description' => 'Generate the time report for a user',
        'ajax' => true,
        'type' => 'write',
    ],
    'tool_time_report_poll_report_file' => [
        'classname'   => 'tool_time_report\external',
        'methodname'  => 'poll_report_file',
        'classpath'   => '',
        'description' => 'Seeks for the generated report file',
        'ajax' => true,
        'type' => 'read',
    ],
];
