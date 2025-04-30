<?php


class ffami_vars {


    public function __construct() {
        $this->define_constant('FFAMI_FILE_MAIN', FFAMI_DATA_PATH . FFAMI_UID);
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
