<?php


class ffami_vars {


    public function __construct() {
        $uid = get_option('ffami_uid', FFAMI_UID);
        if ($uid) {
            $this->define_constant('FFAMI_FILE_MAIN', FFAMI_DATA_PATH . $uid);
        }
    }



    private function define_constant($title, $value) {

        $title = strtoupper($title);
        $title = str_replace('-', '_', $title);
        $title = str_replace(' ', '_', $title);
        $title = str_replace('.', '_', $title);

        if (!defined($title)) {
            define($title, $value);
        }
    }
}
