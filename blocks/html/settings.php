<?php

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox('block_html_allowcustomcssclasses', get_string('allowcustomcssclasses', 'block_html'),
                       get_string('configallowcustomcssclasses', 'block_html'), 0));
}


