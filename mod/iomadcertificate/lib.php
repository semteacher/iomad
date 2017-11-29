<?php

// This file is part of the Certificate module for Moodle - http://moodle.org/
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
 * Certificate module core interaction API
 *
 * @package    mod
 * @subpackage iomadcertificate
 * @copyright  Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/querylib.php');
require_once($CFG->dirroot .'/local/email/lib/api.php');
require_once($CFG->dirroot .'/local/email/lib/vars.php');

/** The border image folder */
define('CERT_IMAGE_BORDER', 'borders');
/** The watermark image folder */
define('CERT_IMAGE_WATERMARK', 'watermarks');
/** The signature image folder */
define('CERT_IMAGE_SIGNATURE', 'signatures');
/** The seal image folder */
define('CERT_IMAGE_SEAL', 'seals');

/** Set CERT_PER_PAGE to 0 if you wish to display all iomadcertificates on the report page */
define('CERT_PER_PAGE', 30);

define('CERT_MAX_PER_PAGE', 200);

/**
 * Add iomadcertificate instance.
 *
 * @param stdClass $iomadcertificate
 * @return int new iomadcertificate instance id
 */
function iomadcertificate_add_instance($iomadcertificate) {
    global $DB;

    // Create the iomadcertificate.
    $iomadcertificate->timecreated = time();
    $iomadcertificate->timemodified = $iomadcertificate->timecreated;

    return $DB->insert_record('iomadcertificate', $iomadcertificate);
}

/**
 * Update iomadcertificate instance.
 *
 * @param stdClass $iomadcertificate
 * @return bool true
 */
function iomadcertificate_update_instance($iomadcertificate) {
    global $DB;

    // Update the iomadcertificate.
    $iomadcertificate->timemodified = time();
    $iomadcertificate->id = $iomadcertificate->instance;

    return $DB->update_record('iomadcertificate', $iomadcertificate);
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id
 * @return bool true if successful
 */
function iomadcertificate_delete_instance($id) {
    global $DB;

    // Ensure the iomadcertificate exists
    if (!$iomadcertificate = $DB->get_record('iomadcertificate', array('id' => $id))) {
        return false;
    }

    // Prepare file record object
    if (!$cm = get_coursemodule_from_instance('iomadcertificate', $id)) {
        return false;
    }

    $result = true;
    $DB->delete_records('iomadcertificate_issues', array('iomadcertificateid' => $id));
    if (!$DB->delete_records('iomadcertificate', array('id' => $id))) {
        $result = false;
    }

    // Delete any files associated with the iomadcertificate
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    return $result;
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified iomadcertificate
 * and clean up any related data.
 *
 * Written by Jean-Michel Vedrine
 *
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function iomadcertificate_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'iomadcertificate');
    $status = array();

    if (!empty($data->reset_iomadcertificate)) {
        $sql = "SELECT cert.id
                FROM {iomadcertificate} cert
                WHERE cert.course = :courseid";
        $DB->delete_records_select('iomadcertificate_issues', "iomadcertificateid IN ($sql)", array('courseid' => $data->courseid));
        $status[] = array('component' => $componentstr, 'item' => get_string('iomadcertificateremoved', 'iomadcertificate'), 'error' => false);
    }

    // Updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('iomadcertificate', array('timeopen', 'timeclose'), $data->timeshift, $data->courseid);
        $status[] = array('component' => $componentstr, 'item' => get_string('datechanged'), 'error' => false);
    }

    return $status;
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the iomadcertificate.
 *
 * Written by Jean-Michel Vedrine
 *
 * @param $mform form passed by reference
 */
function iomadcertificate_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'iomadcertificateheader', get_string('modulenameplural', 'iomadcertificate'));
    $mform->addElement('advcheckbox', 'reset_iomadcertificate', get_string('deletissuediomadcertificates', 'iomadcertificate'));
}

/**
 * Course reset form defaults.
 *
 * Written by Jean-Michel Vedrine
 *
 * @param stdClass $course
 * @return array
 */
function iomadcertificate_reset_course_form_defaults($course) {
    return array('reset_iomadcertificate' => 1);
}

/**
 * Returns information about received iomadcertificate.
 * Used for user activity reports.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $mod
 * @param stdClass $iomadcertificate
 * @return stdClass the user outline object
 */
function iomadcertificate_user_outline($course, $user, $mod, $iomadcertificate) {
    global $DB;

    $result = new stdClass;
    if ($issue = $DB->get_record('iomadcertificate_issues', array('iomadcertificateid' => $iomadcertificate->id, 'userid' => $user->id))) {
        $result->info = get_string('issued', 'iomadcertificate');
        $result->time = $issue->timecreated;
    } else {
        $result->info = get_string('notissued', 'iomadcertificate');
    }

    return $result;
}

/**
 * Returns information about received iomadcertificate.
 * Used for user activity reports.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $mod
 * @param stdClass $page
 * @return string the user complete information
 */
function iomadcertificate_user_complete($course, $user, $mod, $iomadcertificate) {
   global $DB, $OUTPUT;

//get context by context module - still error on "user completion (in course)" report!
$cm = get_coursemodule_from_instance('iomadcertificate', $iomadcertificate->id, $course->id);
$contextmodule = context_module::instance($cm->id);
   if ($issue = $DB->get_record('iomadcertificate_issues', array('iomadcertificateid' => $iomadcertificate->id, 'userid' => $user->id))) {
        echo $OUTPUT->box_start();
        echo get_string('issued', 'iomadcertificate') . ": ";
        echo userdate($issue->timecreated);
        iomadcertificate_print_user_files($iomadcertificate, $user->id, $contextmodule);
        echo '<br />';
        echo $OUTPUT->box_end();
    } else {
        print_string('notissuedyet', 'iomadcertificate');
    }
}

/**
 * Must return an array of user records (all data) who are participants
 * for a given instance of iomadcertificate.
 *
 * @param int $iomadcertificateid
 * @return stdClass list of participants
 */
function iomadcertificate_get_participants($iomadcertificateid) {
    global $DB;

    $sql = "SELECT DISTINCT u.id, u.id
            FROM {user} u, {iomadcertificate_issues} a
            WHERE a.iomadcertificateid = :iomadcertificateid
            AND u.id = a.userid";
    return  $DB->get_records_sql($sql, array('iomadcertificateid' => $iomadcertificateid));
}

/**
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_GROUPMEMBERSONLY
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function iomadcertificate_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_BACKUP_MOODLE2:          return true;

        default: return null;
    }
}

/**
 * Function to be run periodically according to the moodle cron
 * TODO:This needs to be done
 */
function iomadcertificate_cron () {
    return true;
}

/**
 * Returns a list of teachers by group
 * for sending email alerts to teachers
 *
 * @param stdClass $iomadcertificate
 * @param stdClass $user
 * @param stdClass $course
 * @param stdClass $cm
 * @return array the teacher array
 */
function iomadcertificate_get_teachers($iomadcertificate, $user, $course, $cm) {
    global $USER, $DB;

    $context = context_module::instance($cm->id);
    $potteachers = get_users_by_capability($context, 'mod/iomadcertificate:manage',
        '', '', '', '', '', '', false, false);
    if (empty($potteachers)) {
        return array();
    }
    $teachers = array();
    if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS) {   // Separate groups are being used
        if ($groups = groups_get_all_groups($course->id, $user->id)) {  // Try to find all groups
            foreach ($groups as $group) {
                foreach ($potteachers as $t) {
                    if ($t->id == $user->id) {
                        continue; // do not send self
                    }
                    if (groups_is_member($group->id, $t->id)) {
                        $teachers[$t->id] = $t;
                    }
                }
            }
        } else {
            // user not in group, try to find teachers without group
            foreach ($potteachers as $t) {
                if ($t->id == $USER->id) {
                    continue; // do not send self
                }
                if (!groups_get_all_groups($course->id, $t->id)) { //ugly hack
                    $teachers[$t->id] = $t;
                }
            }
        }
    } else {
        foreach ($potteachers as $t) {
            if ($t->id == $USER->id) {
                continue; // do not send self
            }
            $teachers[$t->id] = $t;
        }
    }

    return $teachers;
}

/**
 * Alerts teachers by email of received iomadcertificates. First checks
 * whether the option to email teachers is set for this iomadcertificate.
 *
 * @param stdClass $course
 * @param stdClass $iomadcertificate
 * @param stdClass $certrecord
 * @param stdClass $cm course module
 */
function iomadcertificate_email_teachers($course, $iomadcertificate, $certrecord, $cm) {
    global $USER, $CFG, $DB;

    if ($iomadcertificate->emailteachers == 0) {          // No need to do anything
        return;
    }

    $user = $DB->get_record('user', array('id' => $certrecord->userid));

    if ($teachers = iomadcertificate_get_teachers($iomadcertificate, $user, $course, $cm)) {
        $strawarded = get_string('awarded', 'iomadcertificate');
        foreach ($teachers as $teacher) {
            $info = new stdClass;
            $info->student = fullname($USER);
            $info->course = format_string($course->fullname,true);
            $info->iomadcertificate = format_string($iomadcertificate->name,true);
            $info->url = $CFG->wwwroot.'/mod/iomadcertificate/report.php?id='.$cm->id;
            $from = $USER;
            $postsubject = $strawarded . ': ' . $info->student . ' -> ' . $iomadcertificate->name;
            $posttext = iomadcertificate_email_teachers_text($info);
            $posthtml = ($teacher->mailformat == 1) ? iomadcertificate_email_teachers_html($info) : '';

            @email_to_user($teacher, $from, $postsubject, $posttext, $posthtml);  // If it fails, oh well, too bad.
        }
    }
}

/**
 * Alerts others by email of received iomadcertificates. First checks
 * whether the option to email others is set for this iomadcertificate.
 * Uses the email_teachers info.
 * Code suggested by Eloy Lafuente
 *
 * @param stdClass $course
 * @param stdClass $iomadcertificate
 * @param stdClass $certrecord
 * @param stdClass $cm course module
 */
function iomadcertificate_email_others($course, $iomadcertificate, $certrecord, $cm) {
    global $USER, $CFG, $DB;

    if ($iomadcertificate->emailothers) {
       $others = explode(',', $iomadcertificate->emailothers);
        if ($others) {
            $strawarded = get_string('awarded', 'iomadcertificate');
            foreach ($others as $other) {
                $other = trim($other);
                if (validate_email($other)) {
                    $destination = new stdClass;
                    $destination->email = $other;
                    $info = new stdClass;
                    $info->student = fullname($USER);
                    $info->course = format_string($course->fullname, true);
                    $info->iomadcertificate = format_string($iomadcertificate->name, true);
                    $info->url = $CFG->wwwroot.'/mod/iomadcertificate/report.php?id='.$cm->id;
                    $from = $USER;
                    $postsubject = $strawarded . ': ' . $info->student . ' -> ' . $iomadcertificate->name;
                    $posttext = iomadcertificate_email_teachers_text($info);
                    $posthtml = iomadcertificate_email_teachers_html($info);

                    @email_to_user($destination, $from, $postsubject, $posttext, $posthtml);  // If it fails, oh well, too bad.
                }
            }
        }
    }
}

/**
 * Creates the text content for emails to teachers -- needs to be finished with cron
 *
 * @param $info object The info used by the 'emailteachermail' language string
 * @return string
 */
function iomadcertificate_email_teachers_text($info) {
    $posttext = get_string('emailteachermail', 'iomadcertificate', $info) . "\n";

    return $posttext;
}

/**
 * Creates the html content for emails to teachers
 *
 * @param $info object The info used by the 'emailteachermailhtml' language string
 * @return string
 */
function iomadcertificate_email_teachers_html($info) {
    $posthtml  = '<font face="sans-serif">';
    $posthtml .= '<p>' . get_string('emailteachermailhtml', 'iomadcertificate', $info) . '</p>';
    $posthtml .= '</font>';

    return $posthtml;
}

/**
 * Sends the student their issued iomadcertificate from moddata as an email
 * attachment.
 *
 * @param stdClass $course
 * @param stdClass $iomadcertificate
 * @param stdClass $certrecord
 * @param stdClass $context
 */
function iomadcertificate_email_student($course, $iomadcertificate, $certrecord, $context) {
    global $DB, $USER;

    // Get teachers
    if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
        '', '', '', '', false, true)) {
        $users = sort_by_roleassignment_authority($users, $context);
        $teacher = array_shift($users);
    }

    // If we haven't found a teacher yet, look for a non-editing teacher in this course.
    if (empty($teacher) && $users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
        '', '', '', '', false, true)) {
        $users = sort_by_roleassignment_authority($users, $context);
        $teacher = array_shift($users);
    }

    // Ok, no teachers, use administrator name
    if (empty($teacher)) {
        $teacher = fullname(get_admin());
    }

    $info = new stdClass;
    $info->username = fullname($USER);
    $info->iomadcertificate = format_string($iomadcertificate->name, true);
    $info->course = format_string($course->fullname, true);
    $from = fullname($teacher);
    $subject = $info->course . ': ' . $info->iomadcertificate;
    $message = get_string('emailstudenttext', 'iomadcertificate', $info) . "\n";

    // Make the HTML version more XHTML happy  (&amp;)
    $messagehtml = text_to_html(get_string('emailstudenttext', 'iomadcertificate', $info));

    // Remove full-stop at the end if it exists, to avoid "..pdf" being created and being filtered by clean_filename
    $certname = rtrim($iomadcertificate->name, '.');
    $filename = clean_filename("$certname.pdf");

    // Get hashed pathname
    $fs = get_file_storage();

    $component = 'mod_iomadcertificate';
    $filearea = 'issue';
    $filepath = '/';
    $files = $fs->get_area_files($context->id, $component, $filearea, $certrecord->id);
    foreach ($files as $f) {
        $filepathname = $f->get_contenthash();
    }
    $attachment = 'filedir/'.iomadcertificate_path_from_hash($filepathname).'/'.$filepathname;
    $attachname = $filename;

    return email_to_user($USER, $from, $subject, $message, $messagehtml, $attachment, $attachname);
}

/**
 * Retrieve iomadcertificate path from hash
 *
 * @param array $contenthash
 * @return string the path
 */
function iomadcertificate_path_from_hash($contenthash) {
    $l1 = $contenthash[0].$contenthash[1];
    $l2 = $contenthash[2].$contenthash[3];
    return "$l1/$l2";
}

/**
 * Serves iomadcertificate issues and other files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool|nothing false if file not found, does not return anything if found - just send the file
 */
function iomadcertificate_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    if (!$iomadcertificate = $DB->get_record('iomadcertificate', array('id' => $cm->instance))) {
        return false;
    }

    require_login($course, false, $cm);

    require_once($CFG->libdir.'/filelib.php');

    if ($filearea === 'issue') {
        $certrecord = (int)array_shift($args);

        if (!$certrecord = $DB->get_record('iomadcertificate_issues', array('id' => $certrecord))) {
            return false;
        }

        if ($USER->id != $certrecord->userid and !has_capability('mod/iomadcertificate:manage', $context)) {
            return false;
        }

        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/mod_iomadcertificate/issue/$certrecord->id/$relativepath";

        $fs = get_file_storage();
        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 0, 0, true); // download MUST be forced - security!
    }
}

/**
 * This function returns success or failure of file save
 *
 * @param string $pdf is the string contents of the pdf
 * @param int $certrecordid the iomadcertificate issue record id
 * @param string $filename pdf filename
 * @param int $contextid context id
 * @return bool return true if successful, false otherwise
 */
function iomadcertificate_save_pdf($pdf, $certrecordid, $filename, $contextid) {
    global $DB, $USER;

    if (empty($certrecordid)) {
        return false;
    }

    if (empty($pdf)) {
        return false;
    }

    $fs = get_file_storage();

    // Prepare file record object
    $component = 'mod_iomadcertificate';
    $filearea = 'issue';
    $filepath = '/';
    $fileinfo = array(
        'contextid' => $contextid,   // ID of context
        'component' => $component,   // usually = table name
        'filearea'  => $filearea,     // usually = table name
        'itemid'    => $certrecordid,  // usually = ID of row in table
        'filepath'  => $filepath,     // any path beginning and ending in /
        'filename'  => $filename,    // any filename
        'mimetype'  => 'application/pdf',    // any filename
        'userid'    => $USER->id);

    // If the file exists, delete it and recreate it. This is to ensure that the
    // latest iomadcertificate is saved on the server. For example, the student's grade
    // may have been updated. This is a quick dirty hack.
    if ($fs->file_exists($contextid, $component, $filearea, $certrecordid, $filepath, $filename)) {
        $fs->delete_area_files($contextid, $component, $filearea, $certrecordid);
    }

    $fs->create_file_from_string($fileinfo, $pdf);

    return true;
}

/**
 * Produces a list of links to the issued iomadcertificates.  Used for report.
 *
 * @param stdClass $iomadcertificate
 * @param int $userid
 * @param stdClass $context
 * @return string return the user files
 */
function iomadcertificate_print_user_files($iomadcertificate, $userid, $context) {
    global $CFG, $DB, $OUTPUT;

    $output = '';

    $certrecord = $DB->get_record('iomadcertificate_issues', array('userid' => $userid, 'iomadcertificateid' => $iomadcertificate->id));
    $fs = get_file_storage();
    $browser = get_file_browser();

    $component = 'mod_iomadcertificate';
    $filearea = 'issue';
    $files = $fs->get_area_files($context, $component, $filearea, $certrecord->id);
    foreach ($files as $file) {
        $filename = $file->get_filename();
        $mimetype = $file->get_mimetype();
        $link = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context.'/mod_iomadcertificate/issue/'.$certrecord->id.'/'.$filename);

        $output = '<img src="'.$OUTPUT->image_url(file_mimetype_icon($file->get_mimetype())).'" height="16" width="16" alt="'.$file->get_mimetype().'" />&nbsp;'.
                  '<a href="'.$link.'" >'.s($filename).'</a>';

    }
    $output .= '<br />';
    $output = '<div class="files">'.$output.'</div>';

    return $output;
}

/**
 * Inserts preliminary user data when a iomadcertificate is viewed.
 * Prevents form from issuing a iomadcertificate upon browser refresh.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $iomadcertificate
 * @param stdClass $cm
 * @return stdClass the newly created iomadcertificate issue
 */
function iomadcertificate_get_issue($course, $user, $iomadcertificate, $cm) {
    global $DB;

    // Check if there is an issue already, should only ever be one
    if ($certissue = $DB->get_record('iomadcertificate_issues', array('userid' => $user->id, 'iomadcertificateid' => $iomadcertificate->id))) {
        if ($iomadcertificate->enablecertexpire == 1 && $certissue->timeexpiried == 0){
            //update expiration date for early issued certificates
            $certissue->timeexpiried = iomadcertificate_get_expiredate_value($iomadcertificate, $certissue->timecreated);
            $DB->update_record('iomadcertificate_issues', $certissue);
        }
        //TODO: should necessary to remove expiration date if enablecertexpire set to NO?
        return $certissue;
    }

    // Create new iomadcertificate issue record
    $certissue = new stdClass();
    $certissue->iomadcertificateid = $iomadcertificate->id;
    $certissue->userid = $user->id;
    $certissue->code = iomadcertificate_generate_code();
    $certissue->timecreated =  time();

    // add cert expiration date - flyesterwood feature
    if ($iomadcertificate->enablecertexpire == 1) {
        //$certissue->timeexpiried = iomadcertificate_get_expiredate_value($iomadcertificate, $certissue->timecreated);
        $certissue->timeexpiried = iomadcertificate_get_expiredate_no_format($iomadcertificate, $course, $certissue->userid);
    }
    
    $certissue->id = $DB->insert_record('iomadcertificate_issues', $certissue);

    // Email to the teachers and anyone else
    iomadcertificate_email_teachers($course, $iomadcertificate, $certissue, $cm);
    iomadcertificate_email_others($course, $iomadcertificate, $certissue, $cm);

    return $certissue;
}

/**
 * Returns a list of issued iomadcertificates - sorted for report.
 *
 * @param int $iomadcertificateid
 * @param string $sort the sort order
 * @param bool $groupmode are we in group mode ?
 * @param stdClass $cm the course module
 * @param int $page offset
 * @param int $perpage total per page
 * @return stdClass the users
 */
function iomadcertificate_get_issues($iomadcertificateid, $sort="ci.timecreated ASC", $groupmode, $cm, $page = 0, $perpage = 0) {
    global $CFG, $DB;

    // get all users that can manage this iomadcertificate to exclude them from the report.
    $context = context_module::instance($cm->id);

    $conditionssql = '';
    $conditionsparams = array();
    if ($certmanagers = array_keys(get_users_by_capability($context, 'mod/iomadcertificate:manage', 'u.id'))) {
        list($sql, $params) = $DB->get_in_or_equal($certmanagers, SQL_PARAMS_NAMED, 'cert');
        $conditionssql .= "AND NOT u.id $sql \n";
        $conditionsparams += $params;
    }



    $restricttogroup = false;
    if ($groupmode) {
        $currentgroup = groups_get_activity_group($cm);
        if ($currentgroup) {
            $restricttogroup = true;
            $groupusers = array_keys(groups_get_members($currentgroup, 'u.*'));
            if (empty($groupusers)) {
                return array();
            }
        }
    }

    $restricttogrouping = false;

    // if groupmembersonly used, remove users who are not in any group
    if (!empty($CFG->enablegroupings) and $cm->groupmembersonly) {
        if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
            $restricttogrouping = true;
        } else {
            return array();
        }
    }

    if ($restricttogroup || $restricttogrouping) {
        if ($restricttogroup) {
            $allowedusers = $groupusers;
        } else if ($restricttogroup && $restricttogrouping) {
            $allowedusers = array_intersect($groupusers, $groupingusers);
        } else  {
            $allowedusers = $groupingusers;
        }

        list($sql, $params) = $DB->get_in_or_equal($allowedusers, SQL_PARAMS_NAMED, 'grp');
        $conditionssql .= "AND u.id $sql \n";
        $conditionsparams += $params;
    }


    $page = (int) $page;
    $perpage = (int) $perpage;

    // Get all the users that have iomadcertificates issued, should only be one issue per user for a iomadcertificate
    $allparams = $conditionsparams + array('iomadcertificateid' => $iomadcertificateid);

    $users = $DB->get_records_sql("SELECT u.*, ci.code, ci.timecreated, ci.timeexpiried 
                                   FROM {user} u
                                   INNER JOIN {iomadcertificate_issues} ci
                                   ON u.id = ci.userid
                                   WHERE u.deleted = 0
                                   AND ci.iomadcertificateid = :iomadcertificateid
                                   $conditionssql
                                   ORDER BY {$sort}",
                                   $allparams,
                                   $page * $perpage,
                                   $perpage);


    return $users;
}

/**
 * Returns a list of previously issued iomadcertificates--used for reissue.
 *
 * @param int $iomadcertificateid
 * @return stdClass the attempts else false if none found
 */
function iomadcertificate_get_attempts($iomadcertificateid) {
    global $DB, $USER;

    $sql = "SELECT *
            FROM {iomadcertificate_issues} i
            WHERE iomadcertificateid = :iomadcertificateid
            AND userid = :userid";
    if ($issues = $DB->get_records_sql($sql, array('iomadcertificateid' => $iomadcertificateid, 'userid' => $USER->id))) {
        return $issues;
    }

    return false;
}

/**
 * Prints a table of previously issued iomadcertificates--used for reissue.
 *
 * @param stdClass $course
 * @param stdClass $iomadcertificate
 * @param stdClass $attempts
 * @return string the attempt table
 */
function iomadcertificate_print_attempts($course, $iomadcertificate, $attempts) {
    global $OUTPUT, $DB;

    echo $OUTPUT->heading(get_string('summaryofattempts', 'iomadcertificate'));

    // Prepare table header
    $table = new html_table();
    $table->class = 'generaltable';
    $table->head = array(get_string('issued', 'iomadcertificate'));
    $table->align = array('left');
    $table->attributes = array("style" => "width:30%; margin:auto");
    // expiredate column header - flyeastwood
    if ($iomadcertificate->enablecertexpire == 1) {
        $table->head[] = get_string('expiried', 'iomadcertificate');
        $table->align[] = 'left';
        $table->attributes = array("style" => "width:30%; margin:auto");
    }
    
    $gradecolumn = $iomadcertificate->printgrade;
    if ($gradecolumn) {
        $table->head[] = get_string('grade');
        $table->align[] = 'center';
        $table->size[] = '';
    }
    // One row for each attempt
    foreach ($attempts as $attempt) {
        $row = array();

        // prepare strings for time taken and date completed
        $datecompleted = userdate($attempt->timecreated);
        $row[] = $datecompleted;
        // expiredate value - flyeastwood
        if ($iomadcertificate->enablecertexpire == 1) {
            $dateexpired = userdate($attempt->timeexpiried);
            $row[] = $dateexpired;
        }
        
        if ($gradecolumn) {
            $attemptgrade = iomadcertificate_get_grade($iomadcertificate, $course);
            $row[] = $attemptgrade;
        }

        $table->data[$attempt->id] = $row;
    }

    echo html_writer::table($table);
}

/**
 * Get the time the user has spent in the course
 *
 * @param int $courseid
 * @return int the total time spent in seconds
 */
function iomadcertificate_get_course_time($courseid) {
    global $CFG, $USER;

    set_time_limit(0);

    $totaltime = 0;
    $sql = "l.course = :courseid AND l.userid = :userid";
    if ($logs = get_logs($sql, array('courseid' => $courseid, 'userid' => $USER->id), 'l.time ASC', '', '', $totalcount)) {
        foreach ($logs as $log) {
            if (!isset($login)) {
                // For the first time $login is not set so the first log is also the first login
                $login = $log->time;
                $lasthit = $log->time;
                $totaltime = 0;
            }
            $delay = $log->time - $lasthit;
            if ($delay > ($CFG->sessiontimeout * 60)) {
                // The difference between the last log and the current log is more than
                // the timeout Register session value so that we have found a session!
                $login = $log->time;
            } else {
                $totaltime += $delay;
            }
            // Now the actual log became the previous log for the next cycle
            $lasthit = $log->time;
        }

        return $totaltime;
    }

    return 0;
}

/**
 * Get all the modules
 *
 * @return array
 */
function iomadcertificate_get_mods() {
    global $COURSE, $DB;

    $strtopic = get_string("topic");
    $strweek = get_string("week");
    $strsection = get_string("section");

    // Collect modules data
    $modinfo = get_fast_modinfo($COURSE);
    $mods = $modinfo->get_cms();

    $modules = array();
    $sections = $modinfo->get_section_info_all();
    for ($i = 0; $i <= count($sections) - 1; $i++) {
        // should always be true
        if (isset($sections[$i])) {
            $section = $sections[$i];
            if ($section->sequence) {
                switch ($COURSE->format) {
                    case "topics":
                        $sectionlabel = $strtopic;
                    break;
                    case "weeks":
                        $sectionlabel = $strweek;
                    break;
                    default:
                        $sectionlabel = $strsection;
                }

                $sectionmods = explode(",", $section->sequence);
                foreach ($sectionmods as $sectionmod) {
                    if (empty($mods[$sectionmod])) {
                        continue;
                    }
                    $mod = $mods[$sectionmod];
                    $instance = $DB->get_record($mod->modname, array('id' => $mod->instance));
                    if ($grade_items = grade_get_grade_items_for_activity($mod)) {
                        $mod_item = grade_get_grades($COURSE->id, 'mod', $mod->modname, $mod->instance);
                        $item = reset($mod_item->items);
                        if (isset($item->grademax)){
                            $modules[$mod->id] = $sectionlabel . ' ' . $section->section . ' : ' . $instance->name;
                        }
                    }
                }
            }
        }
    }

    return $modules;
}

/**
 * Search through all the modules for grade data for mod_form.
 *
 * @return array
 */
function iomadcertificate_get_grade_options() {
    $gradeoptions['0'] = get_string('no');
    $gradeoptions['1'] = get_string('coursegrade', 'iomadcertificate');

    return $gradeoptions;
}

/**
 * Search through all the modules for grade dates for mod_form.
 *
 * @return array
 */
function iomadcertificate_get_date_options() {
    $dateoptions['0'] = get_string('no');
    $dateoptions['1'] = get_string('issueddate', 'iomadcertificate');
    $dateoptions['2'] = get_string('completiondate', 'iomadcertificate');

    return $dateoptions;
}

/**
 * Search through all the modules for valid intervals for mod_form.
 *
 * @return array
 */
function iomadcertificate_get_validinterval_options() {
    $validintervaloptions['30'] = get_string('valid30days', 'iomadcertificate');
    $validintervaloptions['365'] = get_string('valid1year', 'iomadcertificate');
    $validintervaloptions['730'] = get_string('valid2years', 'iomadcertificate');

    return $validintervaloptions;
}

/**
 * Search through all the modules for certificate expiration and expiration reminder email`s recipients for mod_form.
 *
 * @return array
 */
function iomadcertificate_get_expireemailrecipient_options() {
    $expireemailrecipientoptions['0'] = get_string('no');
    $expireemailrecipientoptions['1'] = get_string('teacher', 'iomadcertificate');
    $expireemailrecipientoptions['2'] = get_string('student', 'iomadcertificate');
    $expireemailrecipientoptions['3'] = get_string('teacherandstudent', 'iomadcertificate');

    return $expireemailrecipientoptions;
}

/**
 * Search through all the modules for reminder about certificate expiration for mod_form.
 *
 * @return array
 */
function iomadcertificate_get_expireemailreminde_options() {
    $expireemailremindeoptions['7'] = get_string('expiredin7days', 'iomadcertificate');
    $expireemailremindeoptions['30'] = get_string('expiredin30days', 'iomadcertificate');

    return $expireemailremindeoptions;
}

/**
 * Fetch all grade categories from the specified course.
 *
 * @param int $courseid the course id
 * @return array
 */
function iomadcertificate_get_grade_categories($courseid) {
    $grade_category_options = array();

    if ($grade_categories = grade_category::fetch_all(array('courseid' => $courseid))) {
        foreach ($grade_categories as $grade_category) {
            if (!$grade_category->is_course_category()) {
                $grade_category_options[-$grade_category->id] = get_string('category') . ' : ' . $grade_category->get_name();
            }
        }
    }

    return $grade_category_options;
}

/**
 * Get the course outcomes for for mod_form print outcome.
 *
 * @return array
 */
function iomadcertificate_get_outcomes() {
    global $COURSE, $DB;

    // get all outcomes in course
    $grade_seq = new grade_tree($COURSE->id, false, true, '', false);
    if ($grade_items = $grade_seq->items) {
        // list of item for menu
        $printoutcome = array();
        foreach ($grade_items as $grade_item) {
            if (isset($grade_item->outcomeid)){
                $itemmodule = $grade_item->itemmodule;
                $printoutcome[$grade_item->id] = $itemmodule . ': ' . $grade_item->get_name();
            }
        }
    }
    if (isset($printoutcome)) {
        $outcomeoptions['0'] = get_string('no');
        foreach ($printoutcome as $key => $value) {
            $outcomeoptions[$key] = $value;
        }
    } else {
        $outcomeoptions['0'] = get_string('nooutcomes', 'iomadcertificate');
    }

    return $outcomeoptions;
}

/**
 * Used for course participation report (in case iomadcertificate is added).
 *
 * @return array
 */
function iomadcertificate_get_view_actions() {
    return array('view', 'view all', 'view report');
}

/**
 * Used for course participation report (in case iomadcertificate is added).
 *
 * @return array
 */
function iomadcertificate_get_post_actions() {
    return array('received');
}

/**
 * Get iomadcertificate types indexed and sorted by name for mod_form.
 *
 * @return array containing the iomadcertificate type
 */
function iomadcertificate_types() {
    $types = array();
    $names = get_list_of_plugins('mod/iomadcertificate/type');
    $sm = get_string_manager();
    foreach ($names as $name) {
        if ($sm->string_exists('type'.$name, 'iomadcertificate')) {
            $types[$name] = get_string('type'.$name, 'iomadcertificate');
        } else {
            $types[$name] = ucfirst($name);
        }
    }
    asort($types);
    return $types;
}

/**
 * Get images for mod_form.
 *
 * @param string $type the image type
 * @return array
 */
function iomadcertificate_get_images($type) {
    global $CFG, $DB;

    switch($type) {
        case CERT_IMAGE_BORDER :
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/borders";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/borders";
            break;
        case CERT_IMAGE_SEAL :
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/seals";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/seals";
            break;
        case CERT_IMAGE_SIGNATURE :
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/signatures";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/signatures";
            break;
        case CERT_IMAGE_WATERMARK :
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/watermarks";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/watermarks";
            break;
    }
    // If valid path
    if (!empty($path)) {
        $options = array();
        $options += iomadcertificate_scan_image_dir($path);
        $options += iomadcertificate_scan_image_dir($uploadpath);

        // Sort images
        ksort($options);

        // Add the 'no' option to the top of the array
        $options = array_merge(array('0' => get_string('no')), $options);

        return $options;
    } else {
        return array();
    }
}

/**
 * Prepare to print an activity grade.
 *
 * @param stdClass $course
 * @param int $moduleid
 * @param int $userid
 * @return stdClass|bool return the mod object if it exists, false otherwise
 */
function iomadcertificate_get_mod_grade($course, $moduleid, $userid) {
    global $DB;

    $cm = $DB->get_record('course_modules', array('id' => $moduleid));
    $module = $DB->get_record('modules', array('id' => $cm->module));

    if ($grade_item = grade_get_grades($course->id, 'mod', $module->name, $cm->instance, $userid)) {
        $item = new grade_item();
        $itemproperties = reset($grade_item->items);
        foreach ($itemproperties as $key => $value) {
            $item->$key = $value;
        }
        $modinfo = new stdClass;
        $modinfo->name = utf8_decode($DB->get_field($module->name, 'name', array('id' => $cm->instance)));
        $grade = $item->grades[$userid]->grade;
        $item->gradetype = GRADE_TYPE_VALUE;
        $item->courseid = $course->id;

        $modinfo->points = grade_format_gradevalue($grade, $item, true, GRADE_DISPLAY_TYPE_REAL, $decimals = 2);
        $modinfo->percentage = grade_format_gradevalue($grade, $item, true, GRADE_DISPLAY_TYPE_PERCENTAGE, $decimals = 2);
        $modinfo->letter = grade_format_gradevalue($grade, $item, true, GRADE_DISPLAY_TYPE_LETTER, $decimals = 0);

        if ($grade) {
            $modinfo->dategraded = $item->grades[$userid]->dategraded;
        } else {
            $modinfo->dategraded = time();
        }
        return $modinfo;
    }

    return false;
}

/**
 * Returns the date to display for the iomadcertificate.
 *
 * @param stdClass $iomadcertificate
 * @param stdClass $certrecord
 * @param stdClass $course
 * @param int $userid
 * @return string the date
 */
function iomadcertificate_get_date($iomadcertificate, $certrecord, $course, $userid = null) {
    global $DB, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    // Set iomadcertificate date to current time, can be overwritten later
    $date = $certrecord->timecreated;

    if ($iomadcertificate->printdate == '2') {
        // Get the enrolment end date
        $sql = "SELECT MAX(c.timecompleted) as timecompleted
                FROM {course_completions} c
                WHERE c.userid = :userid
                AND c.course = :courseid";
        // Do we have a date on the tracking tables.
        $certname = rtrim($iomadcertificate->name, '.');
        $filename = clean_filename("$certname.pdf");
        if (empty($certrecord->trackid) && $timecompleted = $DB->get_record_sql($sql, array('userid' => $userid, 'courseid' => $course->id))) {
            if (!empty($timecompleted->timecompleted)) {
                $date = $timecompleted->timecompleted;
            }
        }
    } else if ($iomadcertificate->printdate > 2) {
        if ($modinfo = iomadcertificate_get_mod_grade($course, $iomadcertificate->printdate, $userid)) {
            $date = $modinfo->dategraded;
        }
    }
    if ($iomadcertificate->printdate > 0) {
        if ($iomadcertificate->datefmt == 1) {
            $iomadcertificatedate = userdate($date, '%B %d, %Y');
        } else if ($iomadcertificate->datefmt == 2) {
            $suffix = iomadcertificate_get_ordinal_number_suffix(userdate($date, '%d'));
            $iomadcertificatedate = userdate($date, '%B %d' . $suffix . ', %Y');
        } else if ($iomadcertificate->datefmt == 3) {
            $iomadcertificatedate = userdate($date, '%d %B %Y');
        } else if ($iomadcertificate->datefmt == 4) {
            $iomadcertificatedate = userdate($date, '%B %Y');
        } else if ($iomadcertificate->datefmt == 5) {
            $iomadcertificatedate = userdate($date, get_string('strftimedate', 'langconfig'));
        }

        return $iomadcertificatedate;
    }

    return '';
}

/**
 * Returns the certificate expiration date to display for the iomadcertificate.
 *
 * @param stdClass $iomadcertificate
 * @param stdClass $certrecord
 * @param stdClass $course
 * @param int $userid
 * @return string the date
 */
function iomadcertificate_get_expiredate($iomadcertificate, $certrecord, $course, $userid = null) {
    global $DB, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    // Set iomadcertificate expired date to user expired time, can be overwritten later
    $date = $certrecord->timeexpiried;

    if ($iomadcertificate->printdate == '2') {
        // Get the enrolment end date
        $sql = "SELECT MAX(c.timecompleted) as timecompleted
                FROM {course_completions} c
                WHERE c.userid = :userid
                AND c.course = :courseid";
        // Do we have a date on the tracking tables.
        $certname = rtrim($iomadcertificate->name, '.');
        $filename = clean_filename("$certname.pdf");
        if (empty($certrecord->trackid) && $timecompleted = $DB->get_record_sql($sql, array('userid' => $userid, 'courseid' => $course->id))) {
            if (!empty($timecompleted->timecompleted)) {
                $date = iomadcertificate_get_expiredate_value($iomadcertificate, $timecompleted->timecompleted);
            }
        }
    } else if ($iomadcertificate->printdate > 2) {
        if ($modinfo = iomadcertificate_get_mod_grade($course, $iomadcertificate->printdate, $userid)) {
            $date = iomadcertificate_get_expiredate_value($iomadcertificate, $modinfo->dategraded);
        }
    }
    if ($iomadcertificate->printdate > 0) {
        if ($iomadcertificate->datefmt == 1) {
            $iomadcertificatedate = userdate($date, '%B %d, %Y');
        } else if ($iomadcertificate->datefmt == 2) {
            $suffix = iomadcertificate_get_ordinal_number_suffix(userdate($date, '%d'));
            $iomadcertificatedate = userdate($date, '%B %d' . $suffix . ', %Y');
        } else if ($iomadcertificate->datefmt == 3) {
            $iomadcertificatedate = userdate($date, '%d %B %Y');
        } else if ($iomadcertificate->datefmt == 4) {
            $iomadcertificatedate = userdate($date, '%B %Y');
        } else if ($iomadcertificate->datefmt == 5) {
            $iomadcertificatedate = userdate($date, get_string('strftimedate', 'langconfig'));
        }

        return $iomadcertificatedate;
    }

    return '';
}

/**
 * Returns the certificate expiration date to display for the iomadcertificate - without formatting!.
 *
 * @param stdClass $iomadcertificate
 * @param stdClass $course
 * @param int $userid
 * @return string the date
 */
function iomadcertificate_get_expiredate_no_format($iomadcertificate, $course, $userid = null) {
    global $DB, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    // Set iomadcertificate expired date to current time, can be overwritten later
    $date = iomadcertificate_get_expiredate_value($iomadcertificate, time());
    
    if ($iomadcertificate->printdate == '2') {
        // Get the enrolment end date
        $sql = "SELECT MAX(c.timecompleted) as timecompleted
                FROM {course_completions} c
                WHERE c.userid = :userid
                AND c.course = :courseid";
        // Do we have a date on the tracking tables.
        $certname = rtrim($iomadcertificate->name, '.');
        $filename = clean_filename("$certname.pdf");
        if ($timecompleted = $DB->get_record_sql($sql, array('userid' => $userid, 'courseid' => $course->id))) {
            if (!empty($timecompleted->timecompleted)) {
                $date = iomadcertificate_get_expiredate_value($iomadcertificate, $timecompleted->timecompleted);
            }
        }
    } else if ($iomadcertificate->printdate > 2) {
        if ($modinfo = iomadcertificate_get_mod_grade($course, $iomadcertificate->printdate, $userid)) {
            $date = iomadcertificate_get_expiredate_value($iomadcertificate, $modinfo->dategraded);
        }
    }
    
    return $date;
}

/**
 * Returns the certificate expiration date value based on cert issue (activity completion) date.
 *
 * @param stdClass $iomadcertificate
 * @param int $issuedate
 * @return int the date
 */
function iomadcertificate_get_expiredate_value($iomadcertificate, $issuedate) {
    //$expiredate = strtotime($issuedate) + strtotime(strval($iomadcertificate->validinterval) . ' days');
    $expiredate = strtotime('+'.strval($iomadcertificate->validinterval) . ' days', $issuedate);   
    if ($iomadcertificate->valid2monthend){
        $expiredate = strtotime(date('Y-m-t',$expiredate));
    }    
    return $expiredate;
}

/**
 * Helper function to return the suffix of the day of
 * the month, eg 'st' if it is the 1st of the month.
 *
 * @param int the day of the month
 * @return string the suffix.
 */
function iomadcertificate_get_ordinal_number_suffix($day) {
    if (!in_array(($day % 100), array(11, 12, 13))) {
        switch ($day % 10) {
            // Handle 1st, 2nd, 3rd
            case 1: return 'st';
            case 2: return 'nd';
            case 3: return 'rd';
        }
    }
    return 'th';
}

/**
 * Returns the grade to display for the iomadcertificate.
 *
 * @param stdClass $iomadcertificate
 * @param stdClass $course
 * @param int $userid
 * @return string the grade result
 */
function iomadcertificate_get_grade($iomadcertificate, $course, $userid = null) {
    global $USER, $DB;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if ($iomadcertificate->printgrade > 0) {
        if ($iomadcertificate->printgrade == 1) {
            if ($course_item = grade_item::fetch_course_item($course->id)) {
                // String used
                $strcoursegrade = get_string('coursegrade', 'iomadcertificate');

                $grade = new grade_grade(array('itemid' => $course_item->id, 'userid' => $userid));
                $course_item->gradetype = GRADE_TYPE_VALUE;
                $coursegrade = new stdClass;
                $coursegrade->points = grade_format_gradevalue($grade->finalgrade, $course_item, true, GRADE_DISPLAY_TYPE_REAL, $decimals = 2);
                $coursegrade->percentage = grade_format_gradevalue($grade->finalgrade, $course_item, true, GRADE_DISPLAY_TYPE_PERCENTAGE, $decimals = 2);
                $coursegrade->letter = grade_format_gradevalue($grade->finalgrade, $course_item, true, GRADE_DISPLAY_TYPE_LETTER, $decimals = 0);

                if ($iomadcertificate->gradefmt == 1) {
                    $grade = $strcoursegrade . ':  ' . $coursegrade->percentage;
                } else if ($iomadcertificate->gradefmt == 2) {
                    $grade = $strcoursegrade . ':  ' . $coursegrade->points;
                } else if ($iomadcertificate->gradefmt == 3) {
                    $grade = $strcoursegrade . ':  ' . $coursegrade->letter;
                }

                return $grade;
            }
        } else { // Print the mod grade
            if ($modinfo = iomadcertificate_get_mod_grade($course, $iomadcertificate->printgrade, $userid)) {
                // String used
                $strgrade = get_string('grade', 'iomadcertificate');
                if ($iomadcertificate->gradefmt == 1) {
                    $grade = $modinfo->name . ' ' . $strgrade . ': ' . $modinfo->percentage;
                } else if ($iomadcertificate->gradefmt == 2) {
                    $grade = $modinfo->name . ' ' . $strgrade . ': ' . $modinfo->points;
                } else if ($iomadcertificate->gradefmt == 3) {
                    $grade = $modinfo->name . ' ' . $strgrade . ': ' . $modinfo->letter;
                }

                return $grade;
            }
        }
    } else if ($iomadcertificate->printgrade < 0) { // Must be a category id.
        if ($category_item = grade_item::fetch(array('itemtype' => 'category', 'iteminstance' => -$iomadcertificate->printgrade))) {
            $category_item->gradetype = GRADE_TYPE_VALUE;

            $grade = new grade_grade(array('itemid' => $category_item->id, 'userid' => $userid));

            $category_grade = new stdClass;
            $category_grade->points = grade_format_gradevalue($grade->finalgrade, $category_item, true, GRADE_DISPLAY_TYPE_REAL, $decimals = 2);
            $category_grade->percentage = grade_format_gradevalue($grade->finalgrade, $category_item, true, GRADE_DISPLAY_TYPE_PERCENTAGE, $decimals = 2);
            $category_grade->letter = grade_format_gradevalue($grade->finalgrade, $category_item, true, GRADE_DISPLAY_TYPE_LETTER, $decimals = 0);

            if ($iomadcertificate->gradefmt == 1) {
                $formattedgrade = $category_grade->percentage;
            } else if ($iomadcertificate->gradefmt == 2) {
                $formattedgrade = $category_grade->points;
            } else if ($iomadcertificate->gradefmt == 3) {
                $formattedgrade = $category_grade->letter;
            }

            return $formattedgrade;
        }
    }

    return '';
}

/**
 * Returns the outcome to display on the iomadcertificate
 *
 * @param stdClass $iomadcertificate
 * @param stdClass $course
 * @return string the outcome
 */
function iomadcertificate_get_outcome($iomadcertificate, $course, $userid=0) {
    global $USER, $DB;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if ($iomadcertificate->printoutcome > 0) {
        if ($grade_item = new grade_item(array('id' => $iomadcertificate->printoutcome))) {
            $outcomeinfo = new stdClass;
            $outcomeinfo->name = $grade_item->get_name();
            $outcome = new grade_grade(array('itemid' => $grade_item->id, 'userid' => $userid));
            $outcomeinfo->grade = grade_format_gradevalue($outcome->finalgrade, $grade_item, true, GRADE_DISPLAY_TYPE_REAL);

            return $outcomeinfo->name . ': ' . $outcomeinfo->grade;
        }
    }

    return '';
}

/**
 * Returns the code to display on the iomadcertificate.
 *
 * @param stdClass $course
 * @param stdClass $certrecord
 * @return string the code
 */
function iomadcertificate_get_code($iomadcertificate, $certrecord) {
    if ($iomadcertificate->printnumber) {
        return $certrecord->code;
    }

    return '';
}

/**
 * Sends text to output given the following params.
 *
 * @param stdClass $pdf
 * @param int $x horizontal position
 * @param int $y vertical position
 * @param char $align L=left, C=center, R=right
 * @param string $font any available font in font directory
 * @param char $style ''=normal, B=bold, I=italic, U=underline
 * @param int $size font size in points
 * @param string $text the text to print
 * @param int $width horizontal dimension of text block
 */
function iomadcertificate_print_text($pdf, $x, $y, $align, $font='freeserif', $style, $size = 10, $text, $width = 0) {
    $pdf->setFont($font, $style, $size);
    $pdf->SetXY($x, $y);
    $pdf->writeHTMLCell($width, 0, '', '', $text, 0, 0, 0, true, $align);
}

/**
 * Creates rectangles for line border for A4 size paper.
 *
 * @param stdClass $pdf
 * @param stdClass $iomadcertificate
 */
function iomadcertificate_draw_frame($pdf, $iomadcertificate) {
    if ($iomadcertificate->bordercolor > 0) {
        if ($iomadcertificate->bordercolor == 1) {
            $color = array(0, 0, 0); // black
        }
        if ($iomadcertificate->bordercolor == 2) {
            $color = array(153, 102, 51); // brown
        }
        if ($iomadcertificate->bordercolor == 3) {
            $color = array(0, 51, 204); // blue
        }
        if ($iomadcertificate->bordercolor == 4) {
            $color = array(0, 180, 0); // green
        }
        switch ($iomadcertificate->orientation) {
            case 'L':
                // create outer line border in selected color
                $pdf->SetLineStyle(array('width' => 1.5, 'color' => $color));
                $pdf->Rect(10, 10, 277, 190);
                // create middle line border in selected color
                $pdf->SetLineStyle(array('width' => 0.2, 'color' => $color));
                $pdf->Rect(13, 13, 271, 184);
                // create inner line border in selected color
                $pdf->SetLineStyle(array('width' => 1.0, 'color' => $color));
                $pdf->Rect(16, 16, 265, 178);
            break;
            case 'P':
                // create outer line border in selected color
                $pdf->SetLineStyle(array('width' => 1.5, 'color' => $color));
                $pdf->Rect(10, 10, 190, 277);
                // create middle line border in selected color
                $pdf->SetLineStyle(array('width' => 0.2, 'color' => $color));
                $pdf->Rect(13, 13, 184, 271);
                // create inner line border in selected color
                $pdf->SetLineStyle(array('width' => 1.0, 'color' => $color));
                $pdf->Rect(16, 16, 178, 265);
            break;
        }
    }
}

/**
 * Creates rectangles for line border for letter size paper.
 *
 * @param stdClass $pdf
 * @param stdClass $iomadcertificate
 */
function iomadcertificate_draw_frame_letter($pdf, $iomadcertificate) {
    if ($iomadcertificate->bordercolor > 0) {
        if ($iomadcertificate->bordercolor == 1)    {
            $color = array(0, 0, 0); //black
        }
        if ($iomadcertificate->bordercolor == 2)    {
            $color = array(153, 102, 51); //brown
        }
        if ($iomadcertificate->bordercolor == 3)    {
            $color = array(0, 51, 204); //blue
        }
        if ($iomadcertificate->bordercolor == 4)    {
            $color = array(0, 180, 0); //green
        }
        switch ($iomadcertificate->orientation) {
            case 'L':
                // create outer line border in selected color
                $pdf->SetLineStyle(array('width' => 4.25, 'color' => $color));
                $pdf->Rect(28, 28, 736, 556);
                // create middle line border in selected color
                $pdf->SetLineStyle(array('width' => 0.2, 'color' => $color));
                $pdf->Rect(37, 37, 718, 538);
                // create inner line border in selected color
                $pdf->SetLineStyle(array('width' => 2.8, 'color' => $color));
                $pdf->Rect(46, 46, 700, 520);
                break;
            case 'P':
                // create outer line border in selected color
                $pdf->SetLineStyle(array('width' => 1.5, 'color' => $color));
                $pdf->Rect(25, 20, 561, 751);
                // create middle line border in selected color
                $pdf->SetLineStyle(array('width' => 0.2, 'color' => $color));
                $pdf->Rect(40, 35, 531, 721);
                // create inner line border in selected color
                $pdf->SetLineStyle(array('width' => 1.0, 'color' => $color));
                $pdf->Rect(51, 46, 509, 699);
            break;
        }
    }
}

/**
 * Prints border images from the borders folder in PNG or JPG formats.
 *
 * @param stdClass $pdf;
 * @param stdClass $iomadcertificate
 * @param int $x x position
 * @param int $y y position
 * @param int $w the width
 * @param int $h the height
 */
function iomadcertificate_print_image($pdf, $iomadcertificate, $type, $x, $y, $w, $h) {
    global $CFG;

    switch($type) {
        case CERT_IMAGE_BORDER :
            $attr = 'borderstyle';
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/$type/$iomadcertificate->borderstyle";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/$type/$iomadcertificate->borderstyle";
            break;
        case CERT_IMAGE_SEAL :
            $attr = 'printseal';
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/$type/$iomadcertificate->printseal";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/$type/$iomadcertificate->printseal";
            break;
        case CERT_IMAGE_SIGNATURE :
            $attr = 'printsignature';
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/$type/$iomadcertificate->printsignature";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/$type/$iomadcertificate->printsignature";
            break;
        case CERT_IMAGE_WATERMARK :
            $attr = 'printwmark';
            $path = "$CFG->dirroot/mod/iomadcertificate/pix/$type/$iomadcertificate->printwmark";
            $uploadpath = "$CFG->dataroot/mod/iomadcertificate/pix/$type/$iomadcertificate->printwmark";
            break;
    }
    // Has to be valid
    if (!empty($path)) {
        switch ($iomadcertificate->$attr) {
            case '0' :
            case '' :
            break;
            default :
                if (file_exists($path)) {
                    $pdf->Image($path, $x, $y, $w, $h);
                }
                if (file_exists($uploadpath)) {
                    $pdf->Image($uploadpath, $x, $y, $w, $h);
                }
            break;
        }
    }
}

/**
 * Generates a 10-digit code of random letters and numbers.
 *
 * @return string
 */
function iomadcertificate_generate_code() {
    global $DB;

    $uniquecodefound = false;
    $code = random_string(10);
    while (!$uniquecodefound) {
        if (!$DB->record_exists('iomadcertificate_issues', array('code' => $code))) {
            $uniquecodefound = true;
        } else {
            $code = random_string(10);
        }
    }

    return $code;
}

/**
 * Scans directory for valid images
 *
 * @param string the path
 * @return array
 */
function iomadcertificate_scan_image_dir($path) {
    // Array to store the images
    $options = array();

    // Start to scan directory
    if (is_dir($path)) {
        if ($handle = opendir($path)) {
            while (false !== ($file = readdir($handle))) {
                if (strpos($file, '.png', 1) || strpos($file, '.jpg', 1) ) {
                    $i = strpos($file, '.');
                    if ($i > 1) {
                        // Set the name
                        $options[$file] = substr($file, 0, $i);
                    }
                }
            }
            closedir($handle);
        }
    }

    return $options;
}

/**
 * Alerts students and teachers on cron by email that certificates are SET TO EXPIRY.
 */
function iomadcertificate_cron_settoexpiry() {
    global $USER, $CFG, $DB;
    
    // Set some defaults.
    $runtime = time();
    $courses = array();
    $allusers = null;

    mtrace("FLYEASTWOOD: Running iomadcertificate email notification cron at ".date('D M Y h:m:s', $runtime));        

    //TODO: for debug use AND (ci.timeexpiried - (ct.expireemailreminde+366)* 86400) < " . $runtime . ") and set task frequency to 2 min
    //TODO: for production use AND (ci.timeexpiried - ct.expireemailreminde * 86400) < " . $runtime . ") and set task frequency one per day
    $allusers = $DB->get_records_sql("SELECT co.id as companyid, co.name, d.id, d.name, c.id as courseid, c.fullname, cc.timecompleted, ct.id as certid, ct.name, ct.validinterval, ct.valid2monthend, ct.expireemailreminde, ci.id as certrecordid, ci.timecreated, ci.timeexpiried, ci.code, u.id as userid, u.firstname, u.lastname, u.username, u.email
                    FROM {iomad_courses} ic
                    JOIN {local_iomad_track} cc
                    ON (ic.courseid = cc.courseid)
                    JOIN {iomadcertificate} ct
                    ON (cc.courseid = ct.course
                        AND ct.enablecertexpire > 0
                        AND ct.expireemailnotify > 0) 
                    JOIN {iomadcertificate_issues} ci   
                    ON (ct.id = ci.iomadcertificateid
                        AND ci.timeexpiried > 0 
                        AND (ci.timeexpiried - ct.expireemailreminde * 86400) < " . $runtime . ") 
                    JOIN {company_users} cu
                    ON (ci.userid = cu.userid)
                    JOIN {company} co
                    ON (cu.companyid = co.id)
                    JOIN {department} d
                    ON (cu.departmentid = d.id)
                    JOIN {course} c
                    ON (ic.courseid = c.id)
                    JOIN {user} u
                    ON (cc.userid = u.id
                        AND u.deleted = 0
                        AND u.suspended = 0)
                    WHERE cc.id IN (
                        SELECT max(id) FROM {local_iomad_track}
                        GROUP BY userid,courseid)");

    if (count($allusers) > 0){
        foreach ($allusers as $compuser) {
            mtrace("FLYEASTWOOD: certificates will expire soon - user userid $compuser->userid");
            if (!$user = $DB->get_record('user', array('id' => $compuser->userid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates will expire soon - user courseid $compuser->courseid");
            if (!$course = $DB->get_record('course', array('id' => $compuser->courseid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates will expire soon - user companyid $compuser->companyid");    
            if (!$company = $DB->get_record('company', array('id' => $compuser->companyid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates will expire soon - user certid $compuser->certid");
            if (!$iomadcertificate = $DB->get_record('iomadcertificate', array('id' => $compuser->certid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates will expire soon - user certissueid $compuser->certissueid");
            if (!$iomadcertificateissues = $DB->get_record('iomadcertificate_issues', array('id' => $compuser->certrecordid))) {
                continue;
            }        

            //TODO: for debug use OR sent > " . $runtime . " - " . ($compuser->expireemailreminde+366) . " * 86400)",
            //TODO: for production use OR sent > " . $runtime . " - " . $compuser->expireemailreminde . " * 86400)",
            if ($DB->get_records_sql("SELECT id FROM {email}
                                  WHERE userid = :userid
                                  AND courseid = :courseid
                                  AND templatename = :templatename
                                  AND (sent IS NULL
                                  OR sent > " . $runtime . " - " . $compuser->expireemailreminde . " * 86400)",
                                  array('userid' => $compuser->userid,
                                        'courseid' => $compuser->courseid,
                                        'templatename' => 'cert_expiry_warn_user'))) {
                mtrace("FLYEASTWOOD: certificates will expire soon - Exit by email_table");                            
                continue;
            }
            if ($iomadcertificate->expireemailnotify > 1 ) {
                mtrace("FLYEASTWOOD: Sending certificate expiration warning email to student $user->email");        
                EmailTemplate::send('cert_expiry_warn_user', array('course' => $course, 'user' => $user, 'company' => $company, 'iomadcertificate' => $iomadcertificate, 'iomadcertificateissues' => $iomadcertificateissues));
            }
            if ($iomadcertificate->expireemailnotify == 1 || $iomadcertificate->expireemailnotify == 3 ) {
                // Send the supervisor email too.
                mtrace("FLYEASTWOOD: Sending certificate expiration warning email to all teachers/managers");
                $certmoduleid = $DB->get_record('modules', array('name' => 'iomadcertificate'));     
                $coursemodules = $DB->get_records('course_modules', array('course' => $compuser->courseid, 'module' => $certmoduleid->id));
                iomadcertificate_cron_expiry_email_teachers('cert_expiry_warn_manager', $course, $user, $company, $iomadcertificate, $iomadcertificateissues, $coursemodules);
            }
        }    
    }
    
    mtrace("FLYEASTWOOD: FINISHED - Sending expiry warning emails to students and teachers are completed");
}

/**
 * Alerts students and teachers on cron by email that certificates are EXPIRIED.
 */
function iomadcertificate_cron_expied() {
    global $USER, $CFG, $DB;
    
    // Set some defaults.
    $runtime = time();
    $courses = array();
    $allusers = null;

    mtrace("FLYEASTWOOD: Running iomadcertificate email EXPIRIED warning cron at ".date('D M Y h:m:s', $runtime));        

    //TODO: for debug use AND (ci.timeexpiried - (ct.expireemailreminde+364)* 86400) < " . $runtime . ") and set task frequency to 2 min
    //TODO: for production use AND ci.timeexpiried < " . $runtime . ") and set task frequency one per day
    $allusers = $DB->get_records_sql("SELECT co.id as companyid, co.name, d.id, d.name, c.id as courseid, c.fullname, cc.timecompleted, ct.id as certid, ct.name, ct.validinterval, ct.valid2monthend, ct.expireemailreminde, ci.id as certrecordid, ci.timecreated, ci.timeexpiried, ci.code, u.id as userid, u.firstname, u.lastname, u.username, u.email
                    FROM {iomad_courses} ic
                    JOIN {local_iomad_track} cc
                    ON (ic.courseid = cc.courseid)
                    JOIN {iomadcertificate} ct
                    ON (cc.courseid = ct.course
                        AND ct.enablecertexpire > 0
                        AND ct.expireemail > 0) 
                    JOIN {iomadcertificate_issues} ci   
                    ON (ct.id = ci.iomadcertificateid
                        AND ci.timeexpiried > 0 
                        AND ci.timeexpiried < " . $runtime . ") 
                    JOIN {company_users} cu
                    ON (ci.userid = cu.userid)
                    JOIN {company} co
                    ON (cu.companyid = co.id)
                    JOIN {department} d
                    ON (cu.departmentid = d.id)
                    JOIN {course} c
                    ON (ic.courseid = c.id)
                    JOIN {user} u
                    ON (cc.userid = u.id
                        AND u.deleted = 0
                        AND u.suspended = 0)
                    WHERE cc.id IN (
                        SELECT max(id) FROM {local_iomad_track}
                        GROUP BY userid,courseid)");

    if (count($allusers) > 0){
        foreach ($allusers as $compuser) {
            mtrace("FLYEASTWOOD: certificates EXPIRIED - user userid $compuser->userid");
            if (!$user = $DB->get_record('user', array('id' => $compuser->userid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates EXPIRIED - user courseid $compuser->courseid");
            if (!$course = $DB->get_record('course', array('id' => $compuser->courseid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates EXPIRIED - user companyid $compuser->companyid");    
            if (!$company = $DB->get_record('company', array('id' => $compuser->companyid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates EXPIRIED - user certid $compuser->certid");
            if (!$iomadcertificate = $DB->get_record('iomadcertificate', array('id' => $compuser->certid))) { 
                continue;
            }
            mtrace("FLYEASTWOOD: certificates EXPIRIED - user certissueid $compuser->certissueid");
            if (!$iomadcertificateissues = $DB->get_record('iomadcertificate_issues', array('id' => $compuser->certrecordid))) {
                continue;
            }        

            //TODO: for debug use OR sent > " . $runtime . " - " . ($compuser->expireemailreminde+365) . " * 86400)",
            //TODO: for production use OR sent > " . $runtime ,
            if ($DB->get_records_sql("SELECT id FROM {email}
                                  WHERE userid = :userid
                                  AND courseid = :courseid
                                  AND templatename = :templatename
                                  AND (sent IS NULL
                                  OR sent > " . $runtime ,
                                  array('userid' => $compuser->userid,
                                        'courseid' => $compuser->courseid,
                                        'templatename' => 'cert_expire_user'))) {
                mtrace("FLYEASTWOOD: certificates EXPIRIED - Exit by email_table");                            
                continue;
            }
            
            if ($iomadcertificate->expireemail > 1 ) {
                mtrace("FLYEASTWOOD: Sending certificate EXPIRIED warning email to student $user->email");        
                EmailTemplate::send('cert_expire_user', array('course' => $course, 'user' => $user, 'company' => $company, 'iomadcertificate' => $iomadcertificate, 'iomadcertificateissues' => $iomadcertificateissues));
            }
            if ($iomadcertificate->expireemail == 1 || $iomadcertificate->expireemail == 3 ) {
                // Send the supervisor email too.
                mtrace("FLYEASTWOOD: Sending certificate EXPIRIED warning email to all teachers/managers");
                $certmoduleid = $DB->get_record('modules', array('name' => 'iomadcertificate'));
                $coursemodules = $DB->get_records('course_modules', array('course' => $compuser->courseid, 'module' => $certmoduleid->id));
                iomadcertificate_cron_expiry_email_teachers('cert_expire_manager', $course, $user, $company, $iomadcertificate, $iomadcertificateissues, $coursemodules);
            }
        }    
    }
    
    mtrace("FLYEASTWOOD: FINISHED - Sending EXPIRIED warning emails to students and teachers are completed");
}

/**
 * Alerts teachers by email of expiried iomadcertificates. First checks
 * whether the option to email teachers is set for this iomadcertificate.
 *
 * @param string $emailtemplatename
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $company
 * @param stdClass $iomadcertificate
 * @param stdClass $certrecord
 * @param array of stdClass $coursemodules list of course modules with certificate activity
 */
function iomadcertificate_cron_expiry_email_teachers($emailtemplatename, $course, $user, $company, $iomadcertificate, $certrecord, $coursemodules) {
    global $USER, $CFG, $DB;
    
    foreach ($coursemodules as $cm) {
        if ($teachers = iomadcertificate_get_teachers($iomadcertificate, $user, $course, $cm)) {
            foreach ($teachers as $teacher) {
                $results = EmailTemplate::send($emailtemplatename, array('course' => $course, 'user' => $teacher, 'company' => $company, 'iomadcertificate' => $iomadcertificate, 'iomadcertificateissues' => $certrecord));
                mtrace("FLYEASTWOOD: Sending expiry warning email $emailtemplatename to teacher $teacher->username - got - $results");
            }
        }
    }
}
