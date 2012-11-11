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
 * Course completion status for a particular user/course
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once("{$CFG->dirroot}/completion/data_object.php");
require_once("{$CFG->libdir}/completionlib.php");

/**
 * Course completion status for a particular user/course
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_completion extends data_object {

    /* @var string $table Database table name that stores completion information */
    public $table = 'course_completions';

    /* @var array $required_fields Array of required table fields, must start with 'id'. */
    public $required_fields = array('id', 'userid', 'course',
        'timeenrolled', 'timestarted', 'timecompleted', 'reaggregate');

    /* @var int $userid User ID */
    public $userid;

    /* @var int $course Course ID */
    public $course;

    /* @var int Time of course enrolment {@link completion_completion::mark_enrolled()} */
    public $timeenrolled;

    /**
     * Time the user started their course completion {@link completion_completion::mark_inprogress()}
     * @var int
     */
    public $timestarted;

    /* @var int Timestamp of course completion {@link completion_completion::mark_complete()} */
    public $timecompleted;

    /* @var int Flag to trigger cron aggregation (timestamp) */
    public $reaggregate;


    /**
     * Finds and returns a data_object instance based on params.
     *
     * @param array $params associative arrays varname = >value
     * @return data_object instance of data_object or false if none found.
     */
    public static function fetch($params) {
        return self::fetch_helper('course_completions', __CLASS__, $params);
    }

    /**
     * Return status of this completion
     *
     * @return bool
     */
    public function is_complete() {
        return (bool) $this->timecompleted;
    }

    /**
     * Mark this user as started (or enrolled) in this course
     *
     * If the user is already marked as started, no change will occur
     *
     * @param integer $timeenrolled Time enrolled (optional)
     */
    public function mark_enrolled($timeenrolled = null) {

        if ($this->timeenrolled === null) {

            if ($timeenrolled === null) {
                $timeenrolled = time();
            }

            $this->timeenrolled = $timeenrolled;
        }

        return $this->_save();
    }

    /**
     * Mark this user as inprogress in this course
     *
     * If the user is already marked as inprogress, the time will not be changed
     *
     * @param integer $timestarted Time started (optional)
     */
    public function mark_inprogress($timestarted = null) {

        $timenow = time();

        // Set reaggregate flag
        $this->reaggregate = $timenow;

        if (!$this->timestarted) {

            if (!$timestarted) {
                $timestarted = $timenow;
            }

            $this->timestarted = $timestarted;
        }

        return $this->_save();
    }

    /**
     * Mark this user complete in this course
     *
     * This generally happens when the required completion criteria
     * in the course are complete.
     *
     * @param integer $timecomplete Time completed (optional)
     * @return void
     */
    public function mark_complete($timecomplete = null) {

        // Never change a completion time
        if ($this->timecompleted) {
            return;
        }

        // Use current time if nothing supplied
        if (!$timecomplete) {
            $timecomplete = time();
        }

        // Set time started
        if (!$this->timestarted) {
            $this->timestarted = $timecomplete;
        }

        // Set time complete
        $this->timecompleted = $timecomplete;

        // Save record
        if ($result = $this->_save()) {
            events_trigger('course_completed', $this->get_record_data());
        }

        return $result;
    }

    /**
     * Save course completion status
     *
     * This method creates a course_completions record if none exists
     * and also calculates the timeenrolled date if the record is being
     * created
     *
     * @access  private
     * @return  bool
     */
    private function _save() {
        // Make sure timeenrolled is not null
        if (!$this->timeenrolled) {
            $this->timeenrolled = 0;
        }

        // Save record
        if ($this->id) {
            // Update
            return $this->update();
        } else {
            // Create new
            if (!$this->timeenrolled) {
                global $DB;

                // Get earliest current enrolment start date
                // This means timeend > now() and timestart < now()
                $sql = "
                    SELECT
                        ue.timestart
                    FROM
                        {user_enrolments} ue
                    JOIN
                        {enrol} e
                    ON (e.id = ue.enrolid AND e.courseid = :courseid)
                    WHERE
                        ue.userid = :userid
                    AND ue.status = :active
                    AND e.status = :enabled
                    AND (
                        ue.timeend = 0
                     OR ue.timeend > :now
                    )
                    AND ue.timestart < :now2
                    ORDER BY
                        ue.timestart ASC
                ";
                $params = array(
                    'enabled'   => ENROL_INSTANCE_ENABLED,
                    'active'    => ENROL_USER_ACTIVE,
                    'userid'    => $this->userid,
                    'courseid'  => $this->course,
                    'now'       => time(),
                    'now2'      => time()
                );

                if ($enrolments = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE)) {
                    $this->timeenrolled = $enrolments->timestart;
                }

                // If no timeenrolled could be found, use current time
                if (!$this->timeenrolled) {
                    $this->timeenrolled = time();
                }
            }

            // Make sure reaggregate field is not null
            if (!$this->reaggregate) {
                $this->reaggregate = 0;
            }

            // Make sure timestarted is not null
            if (!$this->timestarted) {
                $this->timestarted = 0;
            }

            return $this->insert();
        }
    }
}


/**
 * This function is run when a user is enrolled in the course
 * and creates a completion_completion record for the user if
 * completion is enabled for this course
 *
 * @param   object      $eventdata
 * @return  boolean
 */
function completion_start_user($eventdata) {
    global $DB;

    $courseid = $eventdata->courseid;
    $userid = $eventdata->userid;
    $timestart = $eventdata->timestart;

    // Load course
    if (!$course = $DB->get_record('course', array('id' => $courseid))) {
        debugging('Could not load course id '.$courseid);
        return true;
    }

    // Create completion object
    $cinfo = new completion_info($course);

    // Check completion is enabled for this site and course
    if (!$cinfo->is_enabled()) {
        return false;
    }

    // If completion not set to start on enrollment, do nothing
    if (empty($course->completionstartonenrol)) {
        return false;
    }

    // Create completion record
    $data = array(
        'userid'    => $userid,
        'course'    => $course->id
    );
    $completion = new completion_completion($data);
    if (!$completion->timeenrolled) {
        $completion->timeenrolled = $timestart;
    }

    // Update record
    if (!empty($course->completionstartonenrol)) {
        $completion->mark_inprogress($timestart);
    } else {
        $completion->mark_enrolled();
    }

    return true;
}


/**
 * Triggered by changing course completion criteria, this function
 * bulk marks users as started in the course completion system.
 *
 * @param   integer     $courseid       Course ID
 * @return  bool
 */
function completion_start_user_bulk($courseid) {
    global $DB;

    /**
     * A quick explaination of this horrible looking query
     *
     * It's purpose is to locate all the active participants
     * of a course with course completion enabled.
     *
     * We want to record the user's enrolment start time for the
     * course. This gets tricky because there can be multiple
     * enrolment plugins active in a course, hence the fun
     * case statement.
     */
    $sql = "
        INSERT INTO
            {course_completions}
            (course, userid, timeenrolled, timestarted, reaggregate)
        SELECT
            c.id AS course,
            ue.userid AS userid,
            CASE
                WHEN MIN(ue.timestart) <> 0
                THEN MIN(ue.timestart)
                ELSE ?
            END,
            CASE
                WHEN c.completionstartonenrol = 1
                THEN ?
                ELSE 0
            END,
            ?
        FROM
            {user_enrolments} ue
        INNER JOIN
            {enrol} e
         ON e.id = ue.enrolid
        INNER JOIN
            {course} c
         ON c.id = e.courseid
        LEFT JOIN
            {course_completions} crc
         ON crc.course = c.id
        AND crc.userid = ue.userid
        WHERE
            c.enablecompletion = 1
        AND crc.id IS NULL
        AND c.id = ?
        AND ue.status = ?
        AND e.status = ?
        AND (ue.timeend > ? OR ue.timeend = 0)
        GROUP BY
            c.id,
            ue.userid
    ";

    $now = time();
    $params = array(
        $now,
        $now,
        $now,
        $courseid,
        ENROL_USER_ACTIVE,
        ENROL_INSTANCE_ENABLED,
        $now
    );
    $affected = $DB->execute($sql, $params, true);
}
