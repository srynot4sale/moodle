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
 * @package   mod_forum
 * @copyright 2014 Dan Marsden <dan@danmarsden.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot.'/mod/forum/gradeform.php');
require_once($CFG->dirroot.'/grade/grading/lib.php');
require_once($CFG->dirroot . '/mod/forum/renderable.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$userid = required_param('userid', PARAM_INT); // User id.
$postid = optional_param('postid', 0, PARAM_INT); // Post id.
$action = optional_param('action', '', PARAM_ALPHA);

$params = array();
$params['id'] = $id;
$params['userid'] = $userid;

$PAGE->set_url('/mod/forum/grade.php', $params);

$cm = get_coursemodule_from_id('forum', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);

$forum = $DB->get_record("forum", array("id" => $cm->instance), '*', MUST_EXIST);

$user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);

require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);

// Need mod/forum:grade capability.
require_capability('mod/forum:grade', $context);

// Check advanced grading is enabled for this forum.
$sql = "contextid = ? AND component = ? AND ". $DB->sql_isnotempty('grading_areas', 'activemethod', true, false);
$advancedgrading = $DB->get_records_select_menu('grading_areas', $sql, array($context->id, 'mod_forum'), '', 'areaname, activemethod');

if (!empty($postid) && !empty($advancedgrading['posts'])) {
    $post = forum_get_post_full($postid);
    if ($post->userid != $userid) {
        // This shouldn't happen.
        error("invalid user");
    }
    $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion));
    $area = "posts";
} else if (!empty($advancedgrading['forum'])) {
    // Grade all posts from this user.
    $postid = 0; // Grading posts is not enabled but fall back to grading forum.

    $allnames = get_all_user_name_fields(true, 'u');
    $posts = $DB->get_records_sql("SELECT p.*, $allnames, u.email, u.picture, u.imagealt, g.grade
                                     FROM {forum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          LEFT JOIN {forum_discussions} d ON d.id = p.discussion
                                          LEFT JOIN {forum_grades} g ON g.postid = p.id AND g.userid = p.userid
                                    WHERE d.forum = ? AND u.id = ?
                                 ORDER BY p.discussion, p.created", array($forum->id, $userid));
    $area = "forum";
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('advancedgradingnotsetup', 'forum'));
    echo $OUTPUT->footer();
    exit;
}

$params = array('userid' => $user->id,
                'context' => $context,
                'postid' => $postid,
                'cmid' => $cm->id);
$data = new stdClass();
$data->grade = '';

$formparams = array($forum, $data, $params);
$mform = new mod_forum_grade_form(null,
    $formparams,
    'post',
    '',
    array('class'=>'gradeform'));

if ($action === 'submitgrade') {
    $formdata = $mform->get_data();
    if ($formdata) {
        forum_apply_grade_to_user($formdata, $userid, $area);
        if (!empty($postid)) {
            $url = new moodle_url('/mod/forum/discuss.php', array('d' => $discussion->id, '#p' => $postid));
        } else {
            $url = new moodle_url('/mod/forum/view.php', array('id' => $id));
        }
        redirect($url, get_string('gradesaved', 'forum'), 1);
    }
}


$PAGE->set_title($forum->name);
$PAGE->set_heading($course->fullname);

$renderer = $PAGE->get_renderer('mod_forum');


echo $OUTPUT->header();
if (!empty($post)) {
    // Check to see if we should display a link to allow grading all this users posts.
    if (!empty($advancedgrading['forum'])) {
        echo $OUTPUT->single_button($PAGE->url, get_string('gradeoverall', 'forum'), 'post');
    }
}

echo $renderer->render(new forum_form('gradingform', $mform));

if (!empty($post)) {
    forum_print_post($post, $discussion, $forum, $cm, $course);
} else if (!empty($posts)) {
    $discussions = array();
    foreach ($posts as $post) {
        if (!isset($discussions[$post->discussion])) {
            $discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion));
            $discussions[$discussion->id] = $discussion;
        }
        forum_print_post($post, $discussions[$post->discussion], $forum, $cm, $course, false, false,
                         false, "", "", null, true, null, false, true);

    }
} else {
    echo $OUTPUT->notification(get_string('nopoststograde', 'forum'));
}

echo $OUTPUT->footer($course);
