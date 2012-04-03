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
 * @package mod-hsuforum
 * @copyright Jamie Pratt <me@jamiep.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/formslib.php');

class mod_hsuforum_post_form extends moodleform {

    function definition() {

        global $CFG, $USER;
        $mform    =& $this->_form;

        $course        = $this->_customdata['course'];
        $cm            = $this->_customdata['cm'];
        $coursecontext = $this->_customdata['coursecontext'];
        $modcontext    = $this->_customdata['modcontext'];
        $forum         = $this->_customdata['forum'];
        $post          = $this->_customdata['post'];
        // if $forum->maxbytes == '0' means we should use $course->maxbytes
        if ($forum->maxbytes == '0') {
            $forum->maxbytes = $course->maxbytes;
        }
        // TODO: add max files and max size support
        $editoroptions = array('maxfiles' => EDITOR_UNLIMITED_FILES, 'trusttext'=>true, 'context'=>$modcontext);

        $mform->addElement('header', 'general', '');//fill in the data depending on page params later using set_data
        $mform->addElement('text', 'subject', get_string('subject', 'hsuforum'), 'size="48"');
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('editor', 'message', get_string('message', 'hsuforum'), null, $editoroptions);
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', get_string('required'), 'required', null, 'client');

        if (isset($forum->id) && hsuforum_is_forcesubscribed($forum)) {

            $mform->addElement('static', 'subscribemessage', get_string('subscription', 'hsuforum'), get_string('everyoneissubscribed', 'hsuforum'));
            $mform->addElement('hidden', 'subscribe');
            $mform->setType('subscribe', PARAM_INT);
            $mform->addHelpButton('subscribemessage', 'subscription', 'hsuforum');

        } else if (isset($forum->forcesubscribe)&& $forum->forcesubscribe != HSUFORUM_DISALLOWSUBSCRIBE ||
                   has_capability('moodle/course:manageactivities', $coursecontext)) {

                $options = array();
                $options[0] = get_string('subscribestop', 'hsuforum');
                $options[1] = get_string('subscribestart', 'hsuforum');

                $mform->addElement('select', 'subscribe', get_string('subscription', 'hsuforum'), $options);
                $mform->addHelpButton('subscribe', 'subscription', 'hsuforum');
            } else if ($forum->forcesubscribe == HSUFORUM_DISALLOWSUBSCRIBE) {
                $mform->addElement('static', 'subscribemessage', get_string('subscription', 'hsuforum'), get_string('disallowsubscribe', 'hsuforum'));
                $mform->addElement('hidden', 'subscribe');
                $mform->setType('subscribe', PARAM_INT);
                $mform->addHelpButton('subscribemessage', 'subscription', 'hsuforum');
            }

        if (!empty($forum->maxattachments) && $forum->maxbytes != 1 && has_capability('mod/hsuforum:createattachment', $modcontext))  {  //  1 = No attachments at all
            $mform->addElement('filemanager', 'attachments', get_string('attachment', 'hsuforum'), null,
                array('subdirs'=>0,
                      'maxbytes'=>$forum->maxbytes,
                      'maxfiles'=>$forum->maxattachments,
                      'accepted_types'=>'*',
                      'return_types'=>FILE_INTERNAL));
            $mform->addHelpButton('attachments', 'attachment', 'hsuforum');
        }

        if (empty($post->id) && has_capability('moodle/course:manageactivities', $coursecontext)) { // hack alert
            $mform->addElement('checkbox', 'mailnow', get_string('mailnow', 'hsuforum'));
        }

        if (!empty($CFG->hsuforum_enabletimedposts) && !$post->parent && has_capability('mod/hsuforum:viewhiddentimedposts', $coursecontext)) { // hack alert
            $mform->addElement('header', '', get_string('displayperiod', 'hsuforum'));

            $mform->addElement('date_selector', 'timestart', get_string('displaystart', 'hsuforum'), array('optional'=>true));
            $mform->addHelpButton('timestart', 'displaystart', 'hsuforum');

            $mform->addElement('date_selector', 'timeend', get_string('displayend', 'hsuforum'), array('optional'=>true));
            $mform->addHelpButton('timeend', 'displayend', 'hsuforum');

        } else {
            $mform->addElement('hidden', 'timestart');
            $mform->setType('timestart', PARAM_INT);
            $mform->addElement('hidden', 'timeend');
            $mform->setType('timeend', PARAM_INT);
            $mform->setConstants(array('timestart'=> 0, 'timeend'=>0));
        }

        if (groups_get_activity_groupmode($cm, $course)) { // hack alert
            $groupdata = groups_get_activity_allowed_groups($cm);
            $groupcount = count($groupdata);
            $modulecontext = get_context_instance(CONTEXT_MODULE, $cm->id);
            $contextcheck = has_capability('mod/hsuforum:movediscussions', $modulecontext) && empty($post->parent) && $groupcount > 1;
            if ($contextcheck) {
                $groupinfo = array('0' => get_string('allparticipants'));
                foreach ($groupdata as $grouptemp) {
                    $groupinfo[$grouptemp->id] = $grouptemp->name;
                }
                $mform->addElement('select','groupinfo', get_string('group'), $groupinfo);
                $mform->setDefault('groupinfo', $post->groupid);
            } else {
                if (empty($post->groupid)) {
                    $groupname = get_string('allparticipants');
                } else {
                    $groupname = format_string($groupdata[$post->groupid]->name);
                }
                $mform->addElement('static', 'groupinfo', get_string('group'), $groupname);
            }
        }

        if (!empty($forum->anonymous) and $post->userid == $USER->id and has_capability('mod/hsuforum:revealpost', $modcontext)) {
            $mform->addElement('advcheckbox', 'reveal', get_string('reveal', 'hsuforum'));
            $mform->addHelpButton('reveal', 'reveal', 'hsuforum');
        }

        //-------------------------------------------------------------------------------
        // buttons
        if (isset($post->edit)) { // hack alert
            $submit_string = get_string('savechanges');
        } else {
            $submit_string = get_string('posttoforum', 'hsuforum');
        }
        $this->add_action_buttons(false, $submit_string);

        $mform->addElement('hidden', 'course');
        $mform->setType('course', PARAM_INT);

        $mform->addElement('hidden', 'forum');
        $mform->setType('forum', PARAM_INT);

        $mform->addElement('hidden', 'discussion');
        $mform->setType('discussion', PARAM_INT);

        $mform->addElement('hidden', 'parent');
        $mform->setType('parent', PARAM_INT);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('hidden', 'groupid');
        $mform->setType('groupid', PARAM_INT);

        $mform->addElement('hidden', 'edit');
        $mform->setType('edit', PARAM_INT);

        $mform->addElement('hidden', 'reply');
        $mform->setType('reply', PARAM_INT);
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['timeend']!=0) && ($data['timestart']!=0) && $data['timeend'] <= $data['timestart']) {
            $errors['timeend'] = get_string('timestartenderror', 'hsuforum');
        }
        if (empty($data['message']['text'])) {
            $errors['message'] = get_string('erroremptymessage', 'hsuforum');
        }
        if (empty($data['subject'])) {
            $errors['subject'] = get_string('erroremptysubject', 'hsuforum');
        }
        return $errors;
    }
}

