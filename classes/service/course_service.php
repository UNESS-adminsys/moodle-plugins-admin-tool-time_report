<?php

namespace tool_time_report\service;
class course_service {
    private static $COURSES_CACHE = [];

    public static function get_course_fullname(int $course_id) {
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
}